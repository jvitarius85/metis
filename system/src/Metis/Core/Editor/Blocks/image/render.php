<?php
$src = isset($data['src']) ? (string) $data['src'] : '';
$alt = isset($data['alt']) ? (string) $data['alt'] : '';
$caption = isset($data['caption']) ? (string) $data['caption'] : '';
$link_url = isset($data['link_url']) ? (string) $data['link_url'] : '';
$mode = isset($data['mode']) ? (string) $data['mode'] : 'contained';
$align = isset($data['align']) ? (string) $data['align'] : 'center';

$allowed_modes = [ 'contained', 'wide', 'full_width' ];
if ( ! in_array( $mode, $allowed_modes, true ) ) {
    $mode = 'contained';
}

$allowed_alignments = [ 'left', 'center', 'right' ];
if ( ! in_array( $align, $allowed_alignments, true ) ) {
    $align = 'center';
}

if ($src !== '') {
    echo '<figure class="metis-block-image is-mode-' . metis_esc_attr($mode) . ' is-align-' . metis_esc_attr($align) . '">';
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
