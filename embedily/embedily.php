<?php
/**
 * Plugin Name: Embedily
 * Plugin URI: http://embedily.test/
 * Description: Add videos with a customizable video player.
 * Author: Qoraiche
 * Author URI: https://qoraiche.me/
 * Version: 1.0.0
 * License: GPL2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 *
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

const EMBEDILY_API_URL = 'https://embedily.com/api';
const EMBEDILY_EMBED_URL = 'https://embedily.com/embed';

/**
 * @param $block_content
 * @param $block
 * @return mixed|string
 */
function embedily_video_block_wrapper($block_content, $block)
{
    /**
     * TODO: save all json data and check if not compatible
     * <true> send API Request to update with new data
     */
    if ($block['blockName'] === 'core/video') {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($block['innerHTML']);
        $documentElement = $dom->documentElement;
        $videoElements = $documentElement->getElementsByTagName('video');
        $figureElement = $documentElement->getElementsByTagName('figure');

        $figureId = null;
        $figureClass = null;
        if ($figureElement->length) {
            $figureId = $figureElement[0]->getAttribute('id');
            $figureClass = $figureElement[0]->getAttribute('class');
        }

        if ((bool)$videoElements->length) {
            $poster = $videoElements[0]->getAttribute('poster');
            $controls = $videoElements[0]->hasAttribute('controls');
            $muted = $videoElements[0]->hasAttribute('muted');
            $source = $videoElements[0]->getAttribute('src');
            $loop = $videoElements[0]->hasAttribute('loop');
            $autoplay = $videoElements[0]->hasAttribute('autoplay');
            $tracks = $block['attrs']['tracks'] ?? null;

            $videoData = [
                'poster' => $poster,
                'source' => $source,
                'controls' => $controls ? '1' : '0',
                'muted' => $muted ? '1' : '0',
                'loop' => $loop ? '1' : '0',
                'autoplay' => $autoplay ? '1' : '0',
                'tracks' => $tracks,
            ];

            $metaKey = 'has_embedily_video_' . md5($source);

            $meta = json_decode(get_post_meta(get_the_ID(), $metaKey, true), true);

            if (isset($meta['uuid']) && isset($meta['source'])) {
                $videoData['uuid'] = $meta['uuid'];

                if ($meta !== $videoData) {
                    create_embedily_video($videoData, 'update', $meta['uuid']);
                    update_metadata('post', get_the_ID(), $metaKey, json_encode($videoData));
                }

                if (metadata_exists('post', get_the_ID(), $metaKey)) {
                    return get_video_embed($meta['uuid'], $videoData, $figureId, $figureClass);
                }
            }

            $request = [
                'title' => '#' . get_the_ID() . ' ' . get_the_title(),
                'video_url' => $source,
                'poster_url' => $poster,
                'tracks' => $tracks,
            ];

            //send POST request
            $response = create_embedily_video($request, 'create');

            $data = json_decode($response, true);

            if (isset($data['uuid'])) {
                $videoData['uuid'] = $data['uuid'];
                $uuid = $data['uuid'];

                add_post_meta(get_the_ID(), $metaKey, json_encode($videoData), true);

                return get_video_embed($uuid, $videoData, $figureId, $figureClass);
            }
        }
    }

    return $block_content;
}

add_filter('render_block', 'embedily_video_block_wrapper', 10, 2);

/**
 * @param array $data
 * @param string $type
 * @param string $requestType
 * @return bool|string
 */
function create_embedily_video(array $data = [], string $type = 'create', $uuid = null)
{
    $curl = curl_init();
    $query_string = http_build_query($data);

    $options = get_option('embedily_plugin_settings');

    $token = isset($options['api_key_field']) ? esc_attr($options['api_key_field']) : null;

    $request = '';
    $requestType = 'POST';

    if ($type === 'update') {
        $requestType = 'PATCH';
        $request = '/' . $uuid;
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => EMBEDILY_API_URL . '/videos' . $request,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_POSTFIELDS => $query_string,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => 0,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $requestType,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . trim($token) . ''
        ],
    ]);

    $response = curl_exec($curl);

    curl_close($curl);
    return $response;
}

/**
 * @param string $embedUUid
 * @param array $videoData
 * @param string|null $figureId
 * @param string|null $figureClass
 * @return string
 */
function get_video_embed(string $embedUUid, array $videoData = [], string $figureId = null, string $figureClass = null): string
{
    $embedUrl = EMBEDILY_EMBED_URL . '/' . $embedUUid;
    unset($videoData['source']);
    unset($videoData['poster']);
    unset($videoData['uuid']);
    $iframeQuery = $embedUrl . ' ? ' . http_build_query($videoData);

    return '<figure id="' . $figureId . '" class="' . $figureClass . '">
<iframe src="' . $iframeQuery . '" width="630" height="360" frameborder="0" allowfullscreen webkitallowfullscreen mozallowfullscreen></iframe>
</figure>';
}

function embedily_add_settings_page()
{
    add_options_page(
        'Embedily Settings',
        'Embedily',
        'manage_options',
        'embedily-example-plugin',
        'embedily_render_settings_page'
    );
}

add_action('admin_menu', 'embedily_add_settings_page');

function embedily_render_settings_page()
{
    ?>
    <h2>Embedily Settings</h2>
    <form action="options.php" method="post">
        <?php
        settings_fields('embedily_plugin_settings');
        do_settings_sections('_embedily_plugin_settings_page');
        ?>
        <input
                type="submit"
                name="submit"
                class="button button-primary"
                value="<?php esc_attr_e('Save'); ?>"
        />
    </form>
    <?php
}

function embedily_register_settings()
{
    register_setting(
        'embedily_plugin_settings',
        'embedily_plugin_settings',
        'embedily_validate_plugin_settings'
    );

    add_settings_section(
        'section_one',
        'Authentication',
        'embedily_section_one_text',
        '_embedily_plugin_settings_page'
    );

    add_settings_field(
        'api_key_field',
        'API Key',
        'embedily_render_api_key_field',
        '_embedily_plugin_settings_page',
        'section_one'
    );
}

add_action('admin_init', 'embedily_register_settings');

function embedily_section_one_text()
{
    echo '<p>You can generate and get your API key from the Embedily <a target="_blank" href="https://embedily.com/user/api-tokens">dashboard</p>';
}

/**
 * Render API Key field
 */
function embedily_render_api_key_field()
{
    $options = get_option('embedily_plugin_settings');

    $field = isset($options['api_key_field']) ? esc_attr($options['api_key_field']) : null;

    printf(
        '<input type="text" name="%s" value="%s" />',
        esc_attr('embedily_plugin_settings[api_key_field]'),
        $field
    );
}
