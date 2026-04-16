<?php
/**
 * Plugin Name:  WP LinkedIn Auto Share
 * Plugin URI:   https://wordpress.org/plugins/wp-linkedin-auto-share
 * Description:  WordPress 文章發布後自動同步到 LinkedIn，支援個人帳號與公司頁面。
 * Version:      1.1.0
 * Requires PHP: 8.0
 * Author:       Codotx
 * Author URI:   https://codotx.com
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  cdx
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── 常數 ──────────────────────────────────────────────────────────────────────
define( 'WPLAS_VERSION', '1.1.0' );
define( 'WPLAS_PLUGIN_FILE', __FILE__ );

// ── 主類別 ────────────────────────────────────────────────────────────────────
final class WP_LinkedIn_Auto_Share {

	private static ?self $instance = null;

	// LinkedIn API 端點.
	private const USERINFO_URL = 'https://api.linkedin.com/v2/userinfo';
	private const POSTS_URL   = 'https://api.linkedin.com/v2/ugcPosts';

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );
		add_action( 'wp_after_insert_post', array( $this, 'on_publish' ), 10, 4 );
		add_action( 'admin_notices', array( $this, 'show_share_error' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	// ── 後台選單 ────────────────────────────────────────────────────────────────
	public function register_menu(): void {
		add_options_page(
			'LinkedIn Auto Share',
			'LinkedIn Auto Share',
			'manage_options',
			'wplas-settings',
			array( $this, 'render_settings_page' )
		);
	}

	// ── 簡單 CSS ───────────────────────────────────────────────────────────────
	public function enqueue_styles( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'settings_page_wplas-settings' ), true ) ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', '
			.wplas-card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;margin-bottom:20px;}
			.wplas-card h2{margin-top:0;padding-top:0;border:none;}
			.wplas-code{background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:8px 14px;font-family:monospace;display:inline-block;word-break:break-all;}
			.wplas-badge-connected{color:#1a7f37;font-weight:600;}
			.wplas-mb-label{display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;}
			.wplas-mb-label input{margin:0;}
			.wplas-mb-note{font-size:11px;color:#777;margin-top:8px;line-height:1.5;}
			.wplas-shared-tag{color:#1a7f37;font-size:12px;font-weight:600;}
		' );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// 驗證 Access Token 並取得個人資料
	// ══════════════════════════════════════════════════════════════════════════

	private function verify_and_save_token( string $access_token ): string {
		$response = wp_remote_get( self::USERINFO_URL, array(
			'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			return '驗證失敗：' . $response->get_error_message();
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_code ) {
			return '驗證失敗：LinkedIn 回傳 HTTP ' . $http_code . '，請確認 Token 是否正確。';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['sub'] ) ) {
			return '驗證失敗：無法取得 LinkedIn 使用者資訊。';
		}

		update_option( 'wplas_access_token', $access_token );
		update_option( 'wplas_person_urn', 'urn:li:person:' . $data['sub'] );
		update_option( 'wplas_person_name', isset( $data['name'] ) ? $data['name'] : '' );

		return '';
	}

	// ══════════════════════════════════════════════════════════════════════════
	// 設定頁面
	// ══════════════════════════════════════════════════════════════════════════

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$token_error = '';

		// 儲存設定.
		if ( isset( $_POST['wplas_save'] ) && check_admin_referer( 'wplas_settings' ) ) {
			$access_token_raw  = ( isset( $_POST['wplas_access_token'] ) ) ? sanitize_text_field( wp_unslash( $_POST['wplas_access_token'] ) ) : '';
			$post_template_raw = ( isset( $_POST['wplas_post_template'] ) ) ? sanitize_textarea_field( wp_unslash( $_POST['wplas_post_template'] ) ) : '';
			$auto_all_raw      = ( isset( $_POST['wplas_auto_all_posts'] ) ) ? '1' : '0';

			update_option( 'wplas_post_template', $post_template_raw );
			update_option( 'wplas_auto_all_posts', $auto_all_raw );

			// 如果 token 有變動，驗證並儲存.
			$old_token = get_option( 'wplas_access_token', '' );
			if ( $access_token_raw && $access_token_raw !== $old_token ) {
				$token_error = $this->verify_and_save_token( $access_token_raw );
				if ( empty( $token_error ) ) {
					echo '<div class="notice notice-success is-dismissible"><p>Access Token 驗證成功，帳號已連結！</p></div>';
				}
			} elseif ( empty( $access_token_raw ) && $old_token ) {
				// 清空 token，斷開連結.
				delete_option( 'wplas_access_token' );
				delete_option( 'wplas_person_urn' );
				delete_option( 'wplas_person_name' );
				echo '<div class="notice notice-warning is-dismissible"><p>LinkedIn 帳號已斷開連結。</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div>';
			}
		}

		$access_token   = get_option( 'wplas_access_token', '' );
		$person_name    = get_option( 'wplas_person_name', '' );
		$post_template  = get_option( 'wplas_post_template', "{title}\n\n{excerpt}\n\n閱讀全文：{url}\n\n{tags}" );
		$auto_all       = get_option( 'wplas_auto_all_posts', '0' );
		$is_connected   = ( $access_token && $person_name );

		?>
		<div class="wrap">
			<h1>WP LinkedIn Auto Share</h1>

			<?php if ( $token_error ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $token_error ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'wplas_settings' ); ?>

				<!-- STEP 1 -->
				<div class="wplas-card">
					<h2>Step 1 ── 取得 Access Token</h2>
					<ol>
						<li>前往 <a href="https://www.linkedin.com/developers/apps" target="_blank">LinkedIn Developer Portal</a>，建立或選擇你的 App。</li>
						<li>在 <strong>Products</strong> 頁籤，申請開通 <em>Share on LinkedIn</em> 與 <em>Sign In with LinkedIn using OpenID Connect</em>。</li>
						<li>前往 <strong>OAuth 2.0 tools</strong> 頁面，點擊 <em>Create token</em> 產生 Access Token。</li>
						<li>確保 scope 包含 <code>w_member_social</code>、<code>openid</code>、<code>profile</code>。</li>
					</ol>

					<table class="form-table">
						<tr>
							<th><label for="wplas_access_token">Access Token</label></th>
							<td>
								<input id="wplas_access_token" type="password" name="wplas_access_token"
									value="<?php echo esc_attr( $access_token ); ?>" class="large-text"
									placeholder="貼上從 LinkedIn OAuth 2.0 tools 取得的 Access Token" />
								<?php if ( $is_connected ) : ?>
									<p class="description wplas-badge-connected">✅ 已連結：<?php echo esc_html( $person_name ); ?></p>
								<?php else : ?>
									<p class="description">儲存後會自動驗證 Token 並取得你的 LinkedIn 帳號資訊。清空此欄位可斷開連結。</p>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>

				<!-- STEP 2 貼文範本 -->
				<div class="wplas-card">
					<h2>Step 2 ── 貼文範本</h2>
					<p>可用變數：
						<code>{title}</code> 文章標題
						<code>{excerpt}</code> 摘要（自動截取）
						<code>{url}</code> 文章連結
						<code>{tags}</code> 標籤（自動轉 #hashtag）
					</p>
					<textarea id="wplas_post_template" name="wplas_post_template"
						rows="6" class="large-text"><?php echo esc_textarea( $post_template ); ?></textarea>
					<p class="description">LinkedIn 貼文上限 3,000 字，超出部分會自動截斷。</p>

					<br>
					<label style="display:flex;align-items:center;gap:8px;">
						<input type="checkbox" name="wplas_auto_all_posts" value="1"
							<?php checked( $auto_all, '1' ); ?>>
						<span>自動同步所有新文章（不需逐篇勾選，方便排程發文）</span>
					</label>
				</div>

				<p class="submit">
					<button type="submit" name="wplas_save" class="button button-primary button-large">
						儲存設定
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	// ══════════════════════════════════════════════════════════════════════════
	// Meta Box（文章編輯頁側邊欄）
	// ══════════════════════════════════════════════════════════════════════════

	public function register_meta_box(): void {
		add_meta_box(
			'wplas_meta_box',
			'🔗 LinkedIn Auto Share',
			array( $this, 'render_meta_box' ),
			'post',
			'side',
			'high'
		);
	}

	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'wplas_meta_box', 'wplas_nonce' );

		$access_token = get_option( 'wplas_access_token' );
		$auto_all     = get_option( 'wplas_auto_all_posts', '0' );
		$should_share = get_post_meta( $post->ID, '_wplas_share', true );
		$shared_at    = get_post_meta( $post->ID, '_wplas_shared_at', true );
		$share_error  = get_post_meta( $post->ID, '_wplas_share_error', true );

		// 已連結且全自動模式下預設勾選.
		if ( '' === $should_share && '1' === $auto_all ) {
			$should_share = '1';
		}

		if ( ! $access_token ) {
			echo '<p class="wplas-mb-note">⚠️ 請先到 <a href="' . esc_url( admin_url( 'options-general.php?page=wplas-settings' ) ) . '">設定頁</a> 連結 LinkedIn 帳號。</p>';
			return;
		}

		if ( $shared_at ) {
			echo '<p class="wplas-shared-tag">✅ 已同步到 LinkedIn</p>';
			echo '<p class="wplas-mb-note">同步時間：' . esc_html( $shared_at ) . '</p>';
			// 已同步就不顯示 checkbox，避免重複發文
			return;
		}

		if ( $share_error ) {
			echo '<p style="color:#d63638;font-size:12px;">⚠️ 上次同步失敗：' . esc_html( mb_substr( $share_error, 0, 120 ) ) . '</p>';
		}

		?>
		<label class="wplas-mb-label">
			<input type="checkbox" name="wplas_share" value="1"
				<?php checked( $should_share, '1' ); ?>>
			<span>發布時同步到 LinkedIn</span>
		</label>
		<p class="wplas-mb-note">勾選後，文章發布的瞬間會自動分享到你的 LinkedIn。每篇文章只會發一次，不會重複。</p>
		<?php
	}

	public function save_meta_box( int $post_id ): void {
		$nonce = ( isset( $_POST['wplas_nonce'] ) ) ? sanitize_text_field( wp_unslash( $_POST['wplas_nonce'] ) ) : '';
		if ( ! $nonce ) {
			return;
		}
		if ( ! wp_verify_nonce( $nonce, 'wplas_meta_box' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$value = isset( $_POST['wplas_share'] ) ? '1' : '0';
		update_post_meta( $post_id, '_wplas_share', $value );

		// Gutenberg 的 meta box 在 REST API 發布之後才送出，
		// 所以 wp_after_insert_post 觸發時 _wplas_share 還沒存入。
		// 這裡補檢查：如果文章已發布、有勾選同步、且尚未同步過，就立即發送。
		if ( '1' !== $value ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type ) {
			return;
		}
		$already_shared = get_post_meta( $post_id, '_wplas_shared_at', true );
		if ( $already_shared ) {
			return;
		}
		$this->share_post_to_linkedin( $post );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// 發布觸發
	// ══════════════════════════════════════════════════════════════════════════

	public function on_publish( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before ): void {
		// 只在首次發布時觸發（之前不是 publish，現在是 publish）.
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		if ( $post_before && 'publish' === $post_before->post_status ) {
			return;
		}
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// wp_after_insert_post 在所有 meta 寫入後觸發，直接讀取即可.
		$auto_all     = get_option( 'wplas_auto_all_posts', '0' );
		$should_share = get_post_meta( $post->ID, '_wplas_share', true );

		$do_share = ( '1' === $auto_all ) || ( '1' === $should_share );
		if ( ! $do_share ) {
			return;
		}

		// 避免重複發送
		$already_shared = get_post_meta( $post->ID, '_wplas_shared_at', true );
		if ( $already_shared ) {
			return;
		}

		$this->share_post_to_linkedin( $post );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// 核心：呼叫 LinkedIn Posts API
	// ══════════════════════════════════════════════════════════════════════════

	private function share_post_to_linkedin( WP_Post $post ): void {
		$access_token = get_option( 'wplas_access_token' );
		$person_urn   = get_option( 'wplas_person_urn' );

		if ( ! $access_token || ! $person_urn ) {
			update_post_meta( $post->ID, '_wplas_share_error', '未設定 Access Token 或 Person URN，請重新連結帳號。' );
			return;
		}

		$post_text = $this->build_post_text( $post );
		$post_url  = get_permalink( $post->ID );

		$payload = array(
			'author'          => $person_urn,
			'lifecycleState'  => 'PUBLISHED',
			'specificContent' => array(
				'com.linkedin.ugc.ShareContent' => array(
					'shareCommentary'    => array( 'text' => $post_text ),
					'shareMediaCategory' => 'ARTICLE',
					'media'              => array(
						array(
							'status'      => 'READY',
							'originalUrl' => $post_url,
						),
					),
				),
			),
			'visibility'      => array(
				'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
			),
		);

		$response = wp_remote_post( self::POSTS_URL, array(
			'headers' => array(
				'Authorization'             => 'Bearer ' . $access_token,
				'Content-Type'              => 'application/json',
				'X-Restli-Protocol-Version' => '2.0.0',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			update_post_meta( $post->ID, '_wplas_share_error', $response->get_error_message() );
			return;
		}

		$http_code    = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 201 === $http_code ) {
			// 成功
			update_post_meta( $post->ID, '_wplas_shared_at', current_time( 'Y-m-d H:i:s' ) );
			delete_post_meta( $post->ID, '_wplas_share_error' );
		} else {
			// 失敗，記錄錯誤以便除錯
			$error_data = json_decode( $response_body, true );
			$error_msg  = isset( $error_data['message'] ) ? $error_data['message'] : "HTTP {$http_code}: {$response_body}";
			update_post_meta( $post->ID, '_wplas_share_error', mb_substr( $error_msg, 0, 500 ) );
		}
	}

	// ══════════════════════════════════════════════════════════════════════════
	// 組合貼文文字
	// ══════════════════════════════════════════════════════════════════════════

	private function build_post_text( WP_Post $post ): string {
		$template = get_option( 'wplas_post_template', "{title}\n\n{excerpt}\n\n閱讀全文：{url}\n\n{tags}" );

		// 摘要：優先用手動摘要，沒有則取前 40 個字
		$excerpt = trim( $post->post_excerpt );
		if ( empty( $excerpt ) ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '...' );
		}

		// 標籤轉 hashtag
		$tags = get_the_tags( $post->ID );
		$hashtags = '';
		if ( $tags ) {
			$hashtags = implode( ' ', array_map(
				fn( WP_Term $t ) => '#' . preg_replace( '/\s+/', '', $t->name ),
				$tags
			) );
		}

		$text = str_replace(
			array( '{title}', '{excerpt}', '{url}', '{tags}' ),
			array( $post->post_title, $excerpt, get_permalink( $post->ID ), $hashtags ),
			$template
		);

		// LinkedIn 上限 3,000 字
		return mb_substr( $text, 0, 3000 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// 顯示同步錯誤通知
	// ══════════════════════════════════════════════════════════════════════════

	public function show_share_error(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'post', 'post-new' ), true ) ) {
			return;
		}

		global $post;
		if ( ! $post ) {
			return;
		}

		$error = get_post_meta( $post->ID, '_wplas_share_error', true );
		if ( $error ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p><strong>LinkedIn Auto Share 同步失敗：</strong>%s</p></div>',
				esc_html( $error )
			);
		}
	}

}

// ── 啟動 ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', array( WP_LinkedIn_Auto_Share::class, 'get_instance' ) );
