<?php
/**
 * Clase simple para actualizar un plugin desde GitHub
 */

class GitHub_Updater {
    private $file;
    private $plugin;
    private $basename;
    private $active;
    private $github_url;
    private $github_api_result;

    public function __construct($file, $github_url) {
        $this->file       = $file;
        // $markup=false, $translate=false: no traducir el header evita cargar el
        // text domain antes de 'init' (Notice _load_textdomain_just_in_time en WP 6.7+).
        $this->plugin     = get_plugin_data($file, false, false);
        $this->basename   = plugin_basename($file);
        $this->active     = is_plugin_active($this->basename);
        $this->github_url = $github_url;

        add_filter("pre_set_site_transient_update_plugins", array($this, "check_update"));
        add_filter("plugins_api", array($this, "plugins_api"), 10, 3);
        add_filter("upgrader_post_install", array($this, "after_install"), 10, 3);
    }

    private function get_repo_release_info() {
        if (is_null($this->github_api_result)) {
            // Convertir la URL de GitHub en la de la API
            $api_url = str_replace('https://github.com/', 'https://api.github.com/repos/', $this->github_url);
            $api_url .= '/releases/latest';

            $request = wp_remote_get($api_url, array(
                'headers' => array('User-Agent' => 'WordPress/' . get_bloginfo('version'))
            ));

            if (!is_wp_error($request) && $request['response']['code'] === 200) {
                $this->github_api_result = json_decode($request['body']);
            }
        }
        return $this->github_api_result;
    }

    /**
     * URL del paquete a instalar.
     *
     * Se prefiere el primer adjunto .zip de la release (paquete limpio, con la
     * carpeta del plugin en la raíz). Si la release no trae adjuntos se recurre al
     * zipball que genera GitHub con el código fuente del repositorio.
     */
    private function get_package_url($release_info) {
        if (!empty($release_info->assets) && is_array($release_info->assets)) {
            foreach ($release_info->assets as $asset) {
                $url = isset($asset->browser_download_url) ? $asset->browser_download_url : '';
                if ($url && strtolower(substr($url, -4)) === '.zip') {
                    return $url;
                }
            }
        }
        return isset($release_info->zipball_url) ? $release_info->zipball_url : '';
    }

    public function check_update($transient) {
        if (empty($transient->checked)) return $transient;

        $release_info = $this->get_repo_release_info();
        if ($release_info) {
            $new_version = ltrim($release_info->tag_name, 'v');
            if (version_compare($this->plugin["Version"], $new_version, '<')) {
                $plugin_info = array(
                    "slug"        => $this->basename,
                    "new_version" => $new_version,
                    "url"         => $this->plugin["PluginURI"],
                    "package"     => $this->get_package_url($release_info)
                );
                $transient->response[$this->basename] = (object)$plugin_info;
            }
        }
        return $transient;
    }

    public function plugins_api($false, $action, $response) {
        if ($action !== 'plugin_information' || $response->slug !== $this->basename) return false;

        $release_info = $this->get_repo_release_info();
        if (!$release_info) return false;

        return (object)array(
            "name"          => $this->plugin["Name"],
            "slug"          => $this->basename,
            "version"       => $release_info->tag_name,
            "author"        => $this->plugin["AuthorName"],
            "homepage"      => $this->plugin["PluginURI"],
            "download_link" => $this->get_package_url($release_info),
            "sections"      => array(
                "description" => $this->plugin["Description"],
            )
        );
    }

    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        $install_directory = plugin_dir_path($this->file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;
        if ($this->active) {
            activate_plugin($this->basename);
        }
        return $result;
    }
}
