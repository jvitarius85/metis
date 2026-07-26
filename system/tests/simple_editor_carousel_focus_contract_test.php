<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$source = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/editor/simple-editor.js' );
$start = strpos( $source, "if (target.matches && target.matches('[data-v2-carousel-field]'))" );
$end = $start === false ? false : strpos( $source, "if (target.id === 'metis-v2-carousel-height')", $start );
$handler = $start === false || $end === false ? '' : substr( $source, $start, $end - $start );

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$assert(
    $handler !== ''
    && str_contains( $handler, "if (carouselField === 'action_type') renderStep2Editor();" )
    && ! str_contains( $handler, 'renderStep2Editor(); renderBuilderCanvas();' ),
    'Carousel text fields must not rebuild the editor panel on every input event, while action changes must still refresh their conditional controls.'
);

$height_input_start = strpos( $source, "if (target.id === 'metis-v2-carousel-height')", $end );
$height_input_end = $height_input_start === false ? false : strpos( $source, "if (target.id === 'metis-v2-image-link-url')", $height_input_start );
$height_input_handler = $height_input_start === false || $height_input_end === false ? '' : substr( $source, $height_input_start, $height_input_end - $height_input_start );
$assert(
    $height_input_handler !== ''
    && str_contains( $height_input_handler, 'carouselHeight >= 160 && carouselHeight <= 1200' )
    && ! str_contains( $height_input_handler, 'target.value = sec.content.height' ),
    'Carousel height must allow incomplete multi-digit input and only update the preview once the entered value is valid.'
);

$change_start = strpos( $source, "root.addEventListener('change', function (e)" );
$change_handler = $change_start === false ? '' : substr( $source, $change_start, 12000 );
$assert(
    $change_handler !== ''
    && str_contains( $change_handler, "target.matches && target.matches('[data-v2-carousel-field]')" )
    && str_contains( $change_handler, "carouselField === 'popup_id'" ),
    'Carousel popup selectors must persist through the select change event, including browsers that do not emit an input event for selection changes.'
);

$assert(
    str_contains( $source, 'function carouselTimingOptions(selected, presets)' )
    && str_contains( $source, '<label>Transition Speed</label><select id="metis-v2-carousel-transition-duration"' )
    && str_contains( $source, '<label>Show This Image For</label><select class="metis-se-select" data-v2-carousel-field="duration_ms"' )
    && ! str_contains( $source, 'Transition Speed (ms)' )
    && ! str_contains( $source, 'Show This Image For (ms)' ),
    'Carousel timing controls must use human-readable second-based presets instead of editable millisecond values.'
);

$assert(
    str_contains( $change_handler, "target.id === 'metis-v2-carousel-transition-duration'" ),
    'Carousel transition timing presets must persist through the select change event.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Simple editor carousel focus contract checks passed.\n" );
