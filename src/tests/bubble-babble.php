<?php

// Check Bubble Babble encoding / decoding

namespace KD2;

require __DIR__ . '/_assert.php';
require KD2FW_ROOT . '/BubbleBabble.php';

$tests = array(
    '' => 'xexax',
    'abcd' => 'ximek-domek-gyxox',
    'asdf' => 'ximel-finek-koxex',
    '0123456789' => 'xesaf-casef-fytef-hutif-lovof-nixix',
    'Testing a sentence.' => 'xihak-hysul-gapak-venyd-bumud-besek-heryl-gynek-vumuk-hyrox',
    '1234567890' => 'xesef-disof-gytuf-katof-movif-baxux',
    'Pineapple' => 'xigak-nyryk-humil-bosek-sonax',
);

foreach ($tests as $src=>$expected)
{
    $return = BubbleBabble::Encode($src);
    test($return == $expected, 'Encoding failed for string: ' . $src . ' (expected: ' . $expected . ')');

    $return = BubbleBabble::Decode($return);
    test($return == $src, 'Decoding failed for string: ' . $expected . ' (expected: ' . $src . ')');
}

?>