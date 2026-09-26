"""
Generate the demo station's audio.

Six short WAV files with deliberately awkward lengths (7.003s, 11.017s, ...)
so that millisecond handling is exercised rather than assumed, and so a full
cycle is short enough to watch roll over while testing.

Plain tones, plainly labelled. They are here to make the timing audible, not
to pretend to be music.

    python 52hertz/tools/make-demo-media.py
"""

import math
import os
import struct
import wave

RATE = 22050
OUT = os.path.join(os.path.dirname(__file__), '..', 'media', 'demo')

# name, milliseconds, fundamental Hz, a fifth above for a little body
TRACKS = [
    ('tone-a', 7003, 220.00, True),
    ('tone-b', 11017, 261.63, True),
    ('tone-c', 13009, 164.81, True),
    ('tone-d', 5011, 329.63, False),
    ('tone-e', 9007, 196.00, True),
    ('ident', 3001, 440.00, False),
]


def render(path, ms, hz, harmonic):
    frames = int(RATE * ms / 1000)
    fade = int(RATE * 0.08)
    data = bytearray()

    for i in range(frames):
        t = i / RATE
        # A slow tremolo keeps a long tone from sounding like a test signal.
        amp = 0.30 * (0.88 + 0.12 * math.sin(2 * math.pi * 0.7 * t))
        value = math.sin(2 * math.pi * hz * t)
        if harmonic:
            value += 0.35 * math.sin(2 * math.pi * hz * 1.5 * t)
            value += 0.18 * math.sin(2 * math.pi * hz * 2 * t)
            value /= 1.53
        if i < fade:
            amp *= i / fade
        elif i > frames - fade:
            amp *= max(0.0, (frames - i) / fade)
        data += struct.pack('<h', int(max(-1.0, min(1.0, value * amp)) * 32767))

    with wave.open(path, 'wb') as out:
        out.setnchannels(1)
        out.setsampwidth(2)
        out.setframerate(RATE)
        out.writeframes(bytes(data))

    return frames


def main():
    os.makedirs(OUT, exist_ok=True)
    total = 0
    for name, ms, hz, harmonic in TRACKS:
        path = os.path.join(OUT, name + '.wav')
        frames = render(path, ms, hz, harmonic)
        actual = round(frames * 1000 / RATE, 3)
        total += actual
        print(f'{name+".wav":14} {actual:10.3f} ms  {os.path.getsize(path)/1024:7.0f} KB')
    print(f'{"":14} {total:10.3f} ms  one cycle, before repeats')


if __name__ == '__main__':
    main()
