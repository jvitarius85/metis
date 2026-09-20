<?php
declare(strict_types=1);

echo '<section class="metis-help-empty-state">';
echo '<h2>' . metis_escape_html( $pageKind === 'error' ? 'The requested help page could not be found.' : 'No help documents are available yet.' ) . '</h2>';
echo '<p><a class="metis-btn" href="' . metis_escape_url( $homeUrl ) . '">Back to Help</a></p>';
echo '</section>';
