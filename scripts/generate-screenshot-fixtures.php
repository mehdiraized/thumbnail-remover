<?php
if ( ! extension_loaded( 'gd' ) ) {
	fwrite( STDERR, "The GD extension is required to generate screenshot fixtures.\n" );
	exit( 1 );
}

$output_dir = isset( $argv[1] ) ? rtrim( $argv[1], "/\\" ) : __DIR__ . '/../output/screenshots/fixtures';

if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0777, true ) && ! is_dir( $output_dir ) ) {
	fwrite( STDERR, "Failed to create fixture directory: {$output_dir}\n" );
	exit( 1 );
}

$fixtures = array(
	array(
		'filename' => 'fixture-1.jpg',
		'background' => array( 26, 81, 170 ),
		'accent' => array( 242, 201, 76 ),
		'title' => 'Media Audit',
		'subtitle' => 'Used in the analysis screenshot',
	),
	array(
		'filename' => 'fixture-2.jpg',
		'background' => array( 32, 118, 74 ),
		'accent' => array( 255, 255, 255 ),
		'title' => 'Cleanup Preview',
		'subtitle' => 'Used in the trash workflow screenshot',
	),
	array(
		'filename' => 'fixture-3.jpg',
		'background' => array( 149, 46, 46 ),
		'accent' => array( 255, 220, 120 ),
		'title' => 'Regeneration Demo',
		'subtitle' => 'Used in the regenerate workflow screenshot',
	),
);

foreach ( $fixtures as $index => $fixture ) {
	$image = imagecreatetruecolor( 1600, 1000 );

	$background = imagecolorallocate( $image, $fixture['background'][0], $fixture['background'][1], $fixture['background'][2] );
	$accent = imagecolorallocate( $image, $fixture['accent'][0], $fixture['accent'][1], $fixture['accent'][2] );
	$overlay = imagecolorallocatealpha( $image, 255, 255, 255, 110 );
	$text = imagecolorallocate( $image, 255, 255, 255 );
	$text_muted = imagecolorallocatealpha( $image, 255, 255, 255, 45 );

	imagefill( $image, 0, 0, $background );
	imagefilledrectangle( $image, 80, 80, 1520, 920, $overlay );

	for ( $stripe = 0; $stripe < 6; $stripe++ ) {
		$left = 140 + ( $stripe * 220 );
		imagefilledrectangle( $image, $left, 180, $left + 120, 780, $accent );
	}

	for ( $card = 0; $card < 4; $card++ ) {
		$top = 220 + ( $card * 120 );
		imagefilledrectangle( $image, 930, $top, 1410, $top + 70, $text_muted );
	}

	imagestring( $image, 5, 140, 120, 'Thumbnail Remover Demo', $text );
	imagestring( $image, 5, 140, 160, $fixture['title'], $text );
	imagestring( $image, 3, 140, 200, $fixture['subtitle'], $text );
	imagestring( $image, 4, 140, 840, 'Fixture ' . ( $index + 1 ), $text );

	$target = $output_dir . '/' . $fixture['filename'];
	imagejpeg( $image, $target, 90 );
}
