<?php
/** A harmless stand-in used to test capability detection on every platform. */
declare(strict_types=1);

$tool = $argv[1] ?? '';
$arguments = array_slice($argv, 2);
if (in_array('--slow', $arguments, true)) {
    usleep(500_000);
    exit(0);
}
if ($tool === 'ffmpeg' && in_array('-version', $arguments, true)) {
    echo "ffmpeg version 7.1-test Copyright test\n";
    exit(0);
}
if ($tool === 'ffmpeg' && in_array('-encoders', $arguments, true)) {
    echo "Encoders:\n A..... libmp3lame MP3 (MPEG audio layer 3)\n";
    exit(0);
}
if ($tool === 'ffprobe' && in_array('-version', $arguments, true)) {
    echo "ffprobe version 7.1-test Copyright test\n";
    exit(0);
}
if ($tool === 'stream') {
    echo str_repeat('stream-bytes-', 256);
    exit(0);
}
fwrite(STDERR, "unsupported fixture request\n");
exit(2);
