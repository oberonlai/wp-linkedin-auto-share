<?php
/**
 * Plugin Name:  WP LinkedIn Auto Share
 * Plugin URI:   https://wordpress.org/plugins/wp-linkedin-auto-share
 * Description:  WordPress 文章發布後自動同步到 LinkedIn，支援個人帳號與公司頁面。
 * Version:      1.0.0
 * Requires PHP: 8.0
 * Author:       Suspended Suspended
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  wplas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── 常數 ──────────────────────────────────────────────────────────────────────
define( 'WPLAS_VERSION', '1.0.0' );
define( 'WPLAS_PLUGIN_FILE', __FILE__ );

// ── 主類別 ────────────────────────────────────────────────────────────────────
final class WP_LinkedIn_Auto_Share {

	private static ?self $instance = null;

	// LinkedIn OAuth 端點
	private const AUTH_URL    = 'https://www.linkedin.com/oauth/v2/authorization';
	private const TOKEN_URL   = 'https://www.linkedin.com/oauth/v2/accessToken';
	private const USERINFO_URL = 'https://api.linkedin.com/v2/userinfo';
	private const POSTS_URL   = 'https://api.linkedin.com/v2/ugcPosts';

	// 申請的 OAuth Scope
	private const SCOPES = 'openid profile email w_member_social';

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
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
	// OAuth 流程
	// ══════════════════════════════════════════════════════════════════════════

	public function handle_oauth_callback(): void {
		$page = ( isset( $_GET['page'] ) ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'wplas-settings' !== $page ) {
			return;
		}

		// ── 斷開連結 ───────────────────────────────────────────────────────────
		$disconnect = ( isset( $_GET['wplas_disconnect'] ) ) ? sanitize_text_field( wp_unslash( $_GET['wplas_disconnect'] ) ) : '';
		if ( $disconnect && current_user_can( 'manage_options' ) ) {
			check_admin_referer( 'wplas_disconnect' );
			delete_option( 'wplas_access_token' );
			delete_option( 'wplas_person_urn' );
			delete_option( 'wplas_person_name' );
			wp_safe_redirect( admin_url( 'options-general.php?page=wplas-settings&disconnected=1' ) );
			exit;
		}

		// ── OAuth Callback ─────────────────────────────────────────────────────
		$code  = ( isset( $_GET['code'] ) ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = ( isset( $_GET['state'] ) ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( $code && $state ) {
			$saved_state = get_transient( 'wplas_oauth_state' );

			if ( ! $saved_state || ! hash_equals( $saved_state, $state ) ) {
				wp_die( '安全驗證失敗（state 不符），請重新嘗試連結。', '授權失敗', array( 'back_link' => true ) );
			}

			delete_transient( 'wplas_oauth_state' );
			$this->exchange_code_for_token( $code );
		}
	}

	private function exchange_code_for_token( string $code ): void {
		$response = wp_remote_post( self::TOKEN_URL, array(
			'body'    => array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $this->get_redirect_uri(),
				'client_id'     => get_option( 'wplas_client_id' ),
				'client_secret' => get_option( 'wplas_client_secret' ),
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			wp_die( '取得 Access Token 失敗：' . esc_html( $response->get_error_message() ), '授權失敗', array( 'back_link' => true ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			$msg = isset( $data['error_description'] ) ? $data['error_description'] : ( isset( $data['error'] ) ? $data['error'] : '未知錯誤' );
			wp_die( '取得 Access Token 失敗：' . esc_html( $msg ), '授權失敗', array( 'back_link' => true ) );
		}

		update_option( 'wplas_access_token', $data['access_token'] );
		$this->fetch_and_save_profile( $data['access_token'] );

		wp_safe_redirect( admin_url( 'options-general.php?page=wplas-settings&connected=1' ) );
		exit;
	}

	private function fetch_and_save_profile( string $access_token ): void {
		$response = wp_remote_get( self::USERINFO_URL, array(
			'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $data['sub'] ) ) {
			update_option( 'wplas_person_urn', 'urn:li:person:' . $data['sub'] );
			update_option( 'wplas_person_name', isset( $data['name'] ) ? $data['name'] : '' );
		}
	}

	// ══════════════════════════════════════════════════════════════════════════
	// 設定頁面
	// ══════════════════════════════════════════════════════════════════════════

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 儲存設定.
		$wplas_save = ( isset( $_POST['wplas_save'] ) ) ? sanitize_text_field( wp_unslash( $_POST['wplas_save'] ) ) : '';
		if ( $wplas_save && check_admin_referer( 'wplas_settings' ) ) {
			$client_id_raw     = ( isset( $_POST['wplas_client_id'] ) ) ? sanitize_text_field( wp_unslash( $_POST['wplas_client_id'] ) ) : '';
			$client_secret_raw = ( isset( $_POST['wplas_client_secret'] ) ) ? sanitize_text_field( wp_unslash( $_POST['wplas_client_secret'] ) ) : '';
			$post_template_raw = ( isset( $_POST['wplas_post_template'] ) ) ? sanitize_textarea_field( wp_unslash( $_POST['wplas_post_template'] ) ) : '';
			$auto_all_raw      = ( isset( $_POST['wplas_auto_all_posts'] ) ) ? '1' : '0';

			update_option( 'wplas_client_id', $client_id_raw );
			update_option( 'wplas_client_secret', $client_secret_raw );
			update_option( 'wplas_post_template', $post_template_raw );
			update_option( 'wplas_auto_all_posts', $auto_all_raw );
			echo '<div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div>';
		}

		$client_id      = get_option( 'wplas_client_id', '' );
		$client_secret  = get_option( 'wplas_client_secret', '' );
		$access_token   = get_option( 'wplas_access_token', '' );
		$person_name    = get_option( 'wplas_person_name', '' );
		$post_template  = get_option( 'wplas_post_template', "{title}\n\n{excerpt}\n\n閱讀全文：{url}\n\n{tags}" );
		$auto_all       = get_option( 'wplas_auto_all_posts', '0' );
		$redirect_uri   = $this->get_redirect_uri();
		$is_connected   = ( $access_token && $person_name );

		?>
		<div class="wrap">
			<h1>🔗 WP LinkedIn Auto Share</h1>

			<?php
			$connected    = ( isset( $_GET['connected'] ) ) ? sanitize_text_field( wp_unslash( $_GET['connected'] ) ) : '';
			$disconnected = ( isset( $_GET['disconnected'] ) ) ? sanitize_text_field( wp_unslash( $_GET['disconnected'] ) ) : '';
			?>
			<?php if ( $connected ) : ?>
				<div class="notice notice-success is-dismissible"><p>LinkedIn 帳號連結成功！現在可以開始自動發文了。</p></div>
			<?php endif; ?>
			<?php if ( $disconnected ) : ?>
				<div class="notice notice-warning is-dismissible"><p>LinkedIn 帳號已斷開連結。</p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'wplas_settings' ); ?>

				<!-- STEP 1 -->
				<div class="wplas-card">
					<h2>Step 1 ── 建立 LinkedIn App</h2>
					<ol>
						<li>前往 <a href="https://www.linkedin.com/developers/apps/new" target="_blank">LinkedIn Developer Portal</a>，建立新 App。</li>
						<li>在 App 的 <strong>Auth</strong> 頁籤，把以下網址加入 <em>Authorized redirect URLs</em>：</li>
					</ol>
					<p><span class="wplas-code"><?php echo esc_html( $redirect_uri ); ?></span></p>
					<ol start="3">
						<li>在 <strong>Products</strong> 頁籤，申請開通 <em>Share on LinkedIn</em> 與 <em>Sign In with LinkedIn using OpenID Connect</em>（通常即時核准）。</li>
					</ol>
				</div>

				<!-- STEP 2 -->
				<div class="wplas-card">
					<h2>Step 2 ── 填入 App 憑證</h2>
					<table class="form-table">
						<tr>
							<th><label for="wplas_client_id">Client ID</label></th>
							<td><input id="wplas_client_id" type="text" name="wplas_client_id"
								value="<?php echo esc_attr( $client_id ); ?>" class="regular-text"
								placeholder="86xxxxxxxxxxxxxxxx" /></td>
						</tr>
						<tr>
							<th><label for="wplas_client_secret">Client Secret</label></th>
							<td><input id="wplas_client_secret" type="password" name="wplas_client_secret"
								value="<?php echo esc_attr( $client_secret ); ?>" class="regular-text" /></td>
						</tr>
					</table>
				</div>

				<!-- STEP 3 貼文範本 -->
				<div class="wplas-card">
					<h2>Step 3 ── 貼文範本</h2>
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
						💾 儲存設定
					</button>
				</p>
			</form>

			<hr>

			<!-- STEP 4 連結帳號 -->
			<div class="wplas-card">
				<h2>Step 4 ── 連結 LinkedIn 帳號</h2>
				<?php if ( $is_connected ) : ?>
					<p class="wplas-badge-connected">✅ 已連結：<?php echo esc_html( $person_name ); ?></p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=wplas-settings&wplas_disconnect=1' ), 'wplas_disconnect' ) ); ?>"
						class="button button-secondary"
						onclick="return confirm('確定要斷開 LinkedIn 帳號連結嗎？');">
						🔓 斷開連結
					</a>
				<?php elseif ( $client_id && $client_secret ) : ?>
					<p>尚未連結 LinkedIn 帳號。點擊下方按鈕進行 OAuth 授權。</p>
					<a href="<?php echo esc_url( $this->get_auth_url() ); ?>" class="button button-primary button-large">
						🔗 連結 LinkedIn 帳號
					</a>
				<?php else : ?>
					<p style="color:#999;">請先填入 Client ID / Client Secret 並儲存，再進行帳號連結。</p>
				<?php endif; ?>
			</div>
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
			[ $post->post_title, $excerpt, get_permalink( $post->ID ), $hashtags ],
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

	// ══════════════════════════════════════════════════════════════════════════
	// Helper
	// ══════════════════════════════════════════════════════════════════════════

	private function get_redirect_uri(): string {
		return admin_url( 'options-general.php?page=wplas-settings' );
	}

	private function get_auth_url(): string {
		$state = wp_generate_password( 24, false );
		set_transient( 'wplas_oauth_state', $state, 300 ); // 5 分鐘有效

		return add_query_arg( array(
			'response_type' => 'code',
			'client_id'     => get_option( 'wplas_client_id' ),
			'redirect_uri'  => $this->get_redirect_uri(),
			'state'         => $state,
			'scope'         => self::SCOPES,
		), self::AUTH_URL );
	}
}

// ── 啟動 ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', array( WP_LinkedIn_Auto_Share::class, 'get_instance' ) );
