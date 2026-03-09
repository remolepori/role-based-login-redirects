<?php
/**
 * Plugin Name: Role-based Login Redirects
 * Description: Leite Benutzer nach Login oder Logout je nach Rolle automatisch auf bestimmte Seiten um (inkl. Gast-Rolle).
 * Version:     1.0.0
 * Author:      Remo Lepori
 * License:     GPLv2 or later
 * Text Domain: rblr
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RBLR_Plugin')):

final class RBLR_Plugin {
  const OPT_LOGIN  = 'rblr_login_redirects_v1';  // role => url
  const OPT_LOGOUT = 'rblr_logout_redirects_v1'; // role => url
  const OPT_DEFAULT_LOGIN  = 'rblr_default_login';
  const OPT_DEFAULT_LOGOUT = 'rblr_default_logout';

  public function __construct() {
    add_action('admin_menu',        [$this, 'add_settings_page']);
    add_action('admin_init',        [$this, 'register_settings']);
    add_filter('login_redirect',    [$this, 'handle_login_redirect'], 10, 3);
    add_action('wp_logout',         [$this, 'handle_logout_redirect']);
  }

  /** Admin-Menü */
  public function add_settings_page() {
    add_options_page(
      __('Role-based Login Redirects', 'rblr'),
      __('Login Redirects', 'rblr'),
      'manage_options',
      'rblr',
      [$this, 'render_settings_page']
    );
  }

  /** Settings registrieren */
  public function register_settings() {
    register_setting('rblr_group', self::OPT_LOGIN, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize_redirects'],
      'default' => [],
    ]);
    register_setting('rblr_group', self::OPT_LOGOUT, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize_redirects'],
      'default' => [],
    ]);
    register_setting('rblr_group', self::OPT_DEFAULT_LOGIN, [
      'type' => 'string',
      'sanitize_callback' => 'sanitize_text_field',
      'default' => '',
    ]);
    register_setting('rblr_group', self::OPT_DEFAULT_LOGOUT, [
      'type' => 'string',
      'sanitize_callback' => 'sanitize_text_field',
      'default' => '',
    ]);
  }

  /** Einstellungsseite rendern */
  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $roles = $this->get_all_roles_with_guest();
    $login_redirects  = get_option(self::OPT_LOGIN, []);
    $logout_redirects = get_option(self::OPT_LOGOUT, []);
    $default_login  = get_option(self::OPT_DEFAULT_LOGIN, '');
    $default_logout = get_option(self::OPT_DEFAULT_LOGOUT, '');
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Role-based Login Redirects', 'rblr'); ?></h1>
      <p class="description">
        <?php esc_html_e('Definiere, auf welche Seite Benutzer je nach Rolle nach dem Login bzw. Logout weitergeleitet werden.', 'rblr'); ?>
      </p>

      <form method="post" action="options.php">
        <?php settings_fields('rblr_group'); ?>

        <h2><?php esc_html_e('Login-Weiterleitungen', 'rblr'); ?></h2>
        <table class="form-table">
          <tbody>
          <?php foreach ($roles as $role_key => $role_label): ?>
            <tr>
              <th scope="row"><?php echo esc_html($role_label); ?></th>
              <td>
                <input type="text"
                  name="<?php echo esc_attr(self::OPT_LOGIN . '[' . $role_key . ']'); ?>"
                  value="<?php echo esc_attr($login_redirects[$role_key] ?? ''); ?>"
                  placeholder="/app/"
                  style="width: 300px;"
                />
                <p class="description"><?php esc_html_e('Ziel-URL nach Login (relativ oder absolut).', 'rblr'); ?></p>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <p><strong><?php esc_html_e('Standard-Ziel (falls keine Regel passt):', 'rblr'); ?></strong></p>
        <input type="text" name="<?php echo esc_attr(self::OPT_DEFAULT_LOGIN); ?>" value="<?php echo esc_attr($default_login); ?>" placeholder="/dashboard/" style="width:300px;">

        <hr />

        <h2><?php esc_html_e('Logout-Weiterleitungen', 'rblr'); ?></h2>
        <table class="form-table">
          <tbody>
          <?php foreach ($roles as $role_key => $role_label): ?>
            <tr>
              <th scope="row"><?php echo esc_html($role_label); ?></th>
              <td>
                <input type="text"
                  name="<?php echo esc_attr(self::OPT_LOGOUT . '[' . $role_key . ']'); ?>"
                  value="<?php echo esc_attr($logout_redirects[$role_key] ?? ''); ?>"
                  placeholder="/login/"
                  style="width: 300px;"
                />
                <p class="description"><?php esc_html_e('Ziel-URL nach Logout (relativ oder absolut).', 'rblr'); ?></p>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <p><strong><?php esc_html_e('Standard-Ziel (falls keine Regel passt):', 'rblr'); ?></strong></p>
        <input type="text" name="<?php echo esc_attr(self::OPT_DEFAULT_LOGOUT); ?>" value="<?php echo esc_attr($default_logout); ?>" placeholder="/" style="width:300px;">

        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  /** Nach Login umleiten */
  public function handle_login_redirect($redirect_to, $requested_redirect, $user) {
    if (!is_a($user, 'WP_User')) return $redirect_to;

    $login_redirects = get_option(self::OPT_LOGIN, []);
    $roles = (array) $user->roles;
    foreach ($roles as $role) {
      if (!empty($login_redirects[$role])) {
        return $this->normalize_url($login_redirects[$role]);
      }
    }

    // Fallback auf Standard
    $default = get_option(self::OPT_DEFAULT_LOGIN, '');
    if ($default) {
      return $this->normalize_url($default);
    }

    return $redirect_to;
  }

  /** Nach Logout umleiten */
  public function handle_logout_redirect() {
    $logout_redirects = get_option(self::OPT_LOGOUT, []);
    $default = get_option(self::OPT_DEFAULT_LOGOUT, '');
    $roles = $this->get_current_user_roles_before_logout();

    foreach ($roles as $role) {
      if (!empty($logout_redirects[$role])) {
        wp_safe_redirect($this->normalize_url($logout_redirects[$role]));
        exit;
      }
    }

    if ($default) {
      wp_safe_redirect($this->normalize_url($default));
      exit;
    }
  }

  /** Vor Logout Rollen zwischenspeichern */
  private function get_current_user_roles_before_logout() {
    if (is_user_logged_in()) {
      $u = wp_get_current_user();
      if (!empty($u->roles)) return $u->roles;
    }
    return ['__guest'];
  }

  /** Sanitizer */
  public function sanitize_redirects($input) {
    if (!is_array($input)) return [];
    $clean = [];
    foreach ($input as $role => $url) {
      $url = trim((string) $url);
      if ($url === '') continue;
      $clean[$role] = $url;
    }
    return $clean;
  }

  /** Rollen-Liste inkl. Gast */
  private function get_all_roles_with_guest() {
    $roles = [];
    if (function_exists('wp_roles')) {
      $wp_roles = wp_roles();
      if ($wp_roles && !empty($wp_roles->roles)) {
        foreach ($wp_roles->roles as $key => $def) {
          $label = isset($def['name']) ? $def['name'] : $key;
          if (function_exists('translate_user_role')) {
            $label = translate_user_role($label);
          }
          $roles[$key] = $label;
        }
      }
    }
    $roles['__guest'] = __('Guest (Nicht eingeloggte Benutzer)', 'rblr');
    asort($roles, SORT_NATURAL | SORT_FLAG_CASE);
    return $roles;
  }

  /** Relativ → home_url */
  private function normalize_url($url) {
    if (preg_match('#^https?://#i', $url)) return esc_url_raw($url);
    $path = ltrim($url, '/');
    return home_url('/' . $path);
  }
}

endif;

add_action('plugins_loaded', function(){
  if (class_exists('RBLR_Plugin')) {
    $GLOBALS['rblr_plugin'] = new RBLR_Plugin();
  }
});
