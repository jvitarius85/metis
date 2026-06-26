<?php
$src = isset($data['src']) ? (string) $data['src'] : '';
$alt = isset($data['alt']) ? (string) $data['alt'] : '';
$caption = isset($data['caption']) ? (string) $data['caption'] : '';
$link_url = isset($data['link_url']) ? (string) $data['link_url'] : '';
if ($src !== '') {
    echo '<figure class="metis-block-image">';
    if ($link_url !== '') {
        echo '<a class="metis-block-image__link" href="' . metis_esc_attr($link_url) . '">';
    }
    echo '<img src="' . metis_esc_attr($src) . '" alt="' . metis_esc_attr($alt) . '">';
    if ($link_url !== '') {
        echo '</a>';
    }
    if ($caption !== '') {
        echo '<figcaption>' . metis_esc_html($caption) . '</figcaption>';
    }
    echo '</figure>';
}
