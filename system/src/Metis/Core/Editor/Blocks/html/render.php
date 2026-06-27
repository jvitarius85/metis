<?php
$content = isset($data['content']) ? (string) $data['content'] : '';
if (trim($content) === '') {
    echo '<div class="metis-block-html"></div>';
    return;
}

$sanitized = function_exists('metis_runtime_kses_post')
    ? metis_runtime_kses_post($content)
    : strip_tags($content, '<div><span><p><strong><em><b><i><u><br><hr><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><a><img><table><thead><tbody><tr><th><td>');

echo '<div class="metis-block-html">' . $sanitized . '</div>';
