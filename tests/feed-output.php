<?php
// CLI-only regression test. No database, network, or production WordPress is used.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('ABSPATH', __DIR__ . '/');
function add_action(...$args) {}
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function home_url($path = '/') { return 'https://example.test' . $path; }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function feed_content_type($type) { return 'application/rss+xml'; }
function get_bloginfo_rss($key) {
    return ['name' => 'Fixture', 'description' => 'Regression test', 'language' => 'ja'][$key];
}
function get_option($key, $default = false) {
    return [
        'karaage_current_feed_post_ids' => [303, 101, 202],
        'karaage_feed_built_at' => 1700000000,
        'blog_charset' => 'UTF-8',
    ][$key] ?? $default;
}
function update_option(...$args) { throw new RuntimeException('Rendering must not mutate options'); }
function status_header($code) { $GLOBALS['test_status'] = $code; }
function get_posts($args) {
    if ($args['post_status'] !== 'publish' || $args['orderby'] !== 'post__in'
        || $args['post__in'] !== [303, 101, 202]) {
        throw new RuntimeException('Unexpected post selection');
    }
    return $GLOBALS['fixtures'];
}
// WordPress setup_postdata does NOT assign $GLOBALS['post'].
function setup_postdata($candidate) {
    $GLOBALS['test_author'] = $candidate->author;
    return true;
}
function the_title_rss() { echo esc_html($GLOBALS['post']->title ?? ''); }
function the_permalink_rss() { echo esc_url($GLOBALS['post']->url ?? ''); }
function the_guid() { echo esc_html($GLOBALS['post']->guid ?? ''); }
function the_excerpt_rss() { echo $GLOBALS['post']->excerpt ?? ''; }
function get_the_content_feed($type) { return $GLOBALS['post']->body ?? ''; }
function get_the_author() { return $GLOBALS['test_author']; }
function get_post_time($format, $gmt, $candidate) { return gmdate($format, $candidate->time); }
function wp_reset_postdata() {
    $GLOBALS['post'] = $GLOBALS['original_post'];
    $GLOBALS['test_reset'] = true;
}
function check_same($expected, $actual, $label) {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}
$fixtures = [];
foreach ([303, 101, 202] as $id) {
    $fixtures[] = (object) [
        'title' => '記事 ' . $id . ' & テスト',
        'url' => 'https://example.test/?p=' . $id,
        'guid' => 'fixture-' . $id,
        'author' => 'Author ' . $id,
        'time' => 1700000000 + $id * 86400,
        'excerpt' => '<img src="https://example.test/' . $id . '.jpg">Excerpt ' . $id,
        'body' => '<p>Body ' . $id . '</p>',
    ];
}
$original_post = (object) [
    'title' => 'Unrelated main-query post', 'url' => 'https://example.test/?p=999',
    'guid' => 'unrelated', 'excerpt' => 'Wrong excerpt', 'body' => 'Wrong body',
];
if (($argv[1] ?? '') !== 'no-global') { $GLOBALS['post'] = $original_post; }
$plugin = $argv[2] ?? dirname(__DIR__) . '/karaage.php';
require $plugin;
// Production renderer calls exit. Validate its complete output in a shutdown handler.
ob_start();
register_shutdown_function(function () use ($fixtures, $original_post) {
    $output = ob_get_clean();
    try {
        $last = error_get_last();
        if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) {
            throw new RuntimeException($last['message']);
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($output, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if ($xml === false) { throw new RuntimeException('Invalid XML'); }
        check_same(200, $GLOBALS['test_status'] ?? null, 'HTTP status');
        check_same(3, count($xml->channel->item), 'Item count');
        $position = 0;
        foreach ($xml->channel->item as $item) {
            $expected = $fixtures[$position++];
            check_same($expected->title, (string) $item->title, 'Title');
            check_same($expected->url, (string) $item->link, 'Link/order');
            check_same($expected->guid, (string) $item->guid, 'GUID');
            check_same($expected->excerpt, (string) $item->description, 'Excerpt/image');
            check_same($expected->body, (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded, 'Body');
            check_same($expected->author, (string) $item->children('http://purl.org/dc/elements/1.1/')->creator, 'Author');
            check_same(gmdate('D, d M Y H:i:s +0000', $expected->time), (string) $item->pubDate, 'Date');
        }
        check_same(true, $GLOBALS['test_reset'] ?? false, 'Reset called');
        check_same($original_post, $GLOBALS['post'], 'Main-query post restored');
        check_same('https://example.test/feed-pickup/', karaage_feed_url(), 'RSS URL unchanged');
        echo "PASS: three distinct posts, all fields aligned, context restored.\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL: " . $error->getMessage() . "\n");
        exit(1);
    }
});
karaage_render_feed();
