<?php

declare(strict_types=1);

namespace Algo26\IdnaConvert\TranscodeUnicode;

use Algo26\IdnaConvert\Exception\InvalidCharacterException;
use InvalidArgumentException;

class TranscodeUnicode implements TranscodeUnicodeInterface
{
    public const FORMAT_UCS4 = 'ucs4';
    public const FORMAT_UCS4_ARRAY = 'ucs4array';
    public const FORMAT_UTF8 = 'utf8';
    public const FORMAT_UTF7 = 'utf7';
    public const FORMAT_UTF7_IMAP  = 'utf7imap';

    private const VALID_ENCODINGS = [
        self::FORMAT_UCS4,
        self::FORMAT_UCS4_ARRAY,
        self::FORMAT_UTF8,
        self::FORMAT_UTF7,
        self::FORMAT_UTF7_IMAP
    ];

    private bool $safeMode;
    private int $safeCodepoint = 0xFFFC;

    use ByteLengthTrait;

    public function convert(
        $data,
        string $fromEncoding,
        string $toEncoding,
        bool $safeMode = false,
        ?int $safeCodepoint = null
    ) {
        $this->safeMode = $safeMode;
        if ($safeCodepoint !== null) {
            $this->safeCodepoint = $safeCodepoint;
        }

        $fromEncoding = strtolower($fromEncoding);
        $toEncoding   = strtolower($toEncoding);

        if ($fromEncoding === $toEncoding) {
            return $data;
        }

        if (!in_array($fromEncoding, self::VALID_ENCODINGS)) {
            throw new InvalidArgumentException(sprintf('Invalid input format %s', $fromEncoding), 300);
        }
        if (!in_array($toEncoding, self::VALID_ENCODINGS)) {
            throw new InvalidArgumentException(sprintf('Invalid output format %s', $toEncoding), 301);
        }

        if ($fromEncoding !== self::FORMAT_UCS4_ARRAY) {
            $methodName = sprintf('%s_%s', $fromEncoding, self::FORMAT_UCS4_ARRAY);
            $data = $this->$methodName($data);
        }
        if ($toEncoding !== self::FORMAT_UCS4_ARRAY) {
            $methodName = sprintf('%s_%s', self::FORMAT_UCS4_ARRAY, $toEncoding);
            $data = $this->$methodName($data);
        }

        return $data;
    }

    /**
     * @throws InvalidCharacterException
     */
    private function utf8_ucs4array(string $input): array
    {
        $startByte = 0;
        $nextByte = 0;

        $output = [];
        $outputLength = 0;
        $inputLength = $this->getByteLength($input);
        $mode = 'next';
        $test = 'none';
        for ($k = 0; $k < $inputLength; ++$k) {
            $v = ord($input[$k]); // Extract byte from input string

            if ($v < 128) { // We found an ASCII char - copy into string as is
                $output[$outputLength] = $v;
                ++$outputLength;
                if ('add' === $mode) {
                    if ($this->safeMode) {
                        $output[$outputLength - 2] = $this->safeCodepoint;
                        $mode = 'next';
                    } else {
                        throw new InvalidCharacterException(
                            sprintf(
                                'Conversion from UTF-8 to UCS-4 failed: malformed input at byte %d',
                                $k,
                            ),
                            302,
                        );
                    }
                }

                continue;
            }

            if ('next' === $mode) { // Try to find the next start byte; determine the width of the Unicode char
                $startByte = $v;
                $mode = 'add';
                $test = 'range';
                if ($v >> 5 === 6) { // &110xxxxx 10xxxxx
                    $nextByte = 0; // How many times subsequent bit masks must rotate 6bits to the left
                    $v = ($v - 192) << 6;
                } elseif ($v >> 4 === 14) { // &1110xxxx 10xxxxxx 10xxxxxx
                    $nextByte = 1;
                    $v = ($v - 224) << 12;
                } elseif ($v >> 3 === 30) { // &11110xxx 10xxxxxx 10xxxxxx 10xxxxxx
                    $nextByte = 2;
                    $v = ($v - 240) << 18;
                } elseif ($this->safeMode) {
                    $mode = 'next';
                    $output[$outputLength] = $this->safeCodepoint;
                    ++$outputLength;

                    continue;
                } else {
                    throw new InvalidCharacterException(
                        sprintf('This might be UTF-8, but I don\'t understand it at byte %d', $k),
                        303,
                    );
                }
                if (($inputLength - $k - $nextByte) < 2) {
                    $output[$outputLength] = $this->safeCodepoint;
                    $mode = 'no';

                    continue;
                }

                if ('add' === $mode) {
                    $output[$outputLength] = (int)$v;
                    ++$outputLength;

                    continue;
                }
            }
            if ('add' == $mode) {
                if (!$this->safeMode && $test === 'range') {
                    $test = 'none';
                    if (($v < 0xA0 && $startByte === 0xE0)
                        || ($v < 0x90 && $startByte === 0xF0)
                        || ($v > 0x8F && $startByte === 0xF4)
                    ) {
                        throw new InvalidCharacterException(
                            sprintf('Bogus UTF-8 character (out of legal range) at byte %d', $k),
                            304,
                        );
                    }
                }
                if ($v >> 6 === 2) { // Bit mask must be 10xxxxxx
                    $v = ($v - 128) << ($nextByte * 6);
                    $output[($outputLength - 1)] += $v;
                    --$nextByte;
                } else {
                    if ($this->safeMode) {
                        $output[$outputLength - 1] = chr($this->safeCodepoint);
                        $k--;
                        $mode = 'next';

                        continue;
                    } else {
                        throw new InvalidCharacterException(
                            sprintf('Conversion from UTF-8 to UCS-4 failed: malformed input at byte %d', $k),
                            302,
                        );
                    }
                }
                if ($nextByte < 0) {
                    $mode = 'next';
                }
            }
        }

        return $output;
    }

    /**
     * @throws InvalidCharacterException
     */
    private function ucs4array_utf8($input): string
    {
        $output = '';
        foreach ($input as $k => $v) {
            if ($v < 128) { // 7bit are transferred literally
                $output .= chr($v);
            } elseif ($v < (1 << 11)) { // 2 bytes
                $output .= sprintf(
                    '%s%s',
                    chr(192 + ($v >> 6)),
                    chr(128 + ($v & 63))
                );
            } elseif ($v < (1 << 16)) { // 3 bytes
                $output .= sprintf(
                    '%s%s%s',
                    chr(224 + ($v >> 12)),
                    chr(128 + (($v >> 6) & 63)),
                    chr(128 + ($v & 63))
                );
            } elseif ($v < (1 << 21)) { // 4 bytes
                $output .= sprintf(
                    '%s%s%s%s',
                    chr(240 + ($v >> 18)),
                    chr(128 + (($v >> 12) & 63)),
                    chr(128 + (($v >> 6) & 63)),
                    chr(128 + ($v & 63))
                );
            } elseif ($this->safeMode) {
                $output .= $this->safeCodepoint;
            } else {
                throw new InvalidCharacterException(
                    sprintf('Conversion from UCS-4 to UTF-8 failed: malformed input at byte %d', $k),
                    305,
                );
            }
        }

        return $output;
    }

    private function utf7imap_ucs4array(string $input): array
    {
        return $this->utf7_ucs4array(str_replace(',', '/', $input), '&');
    }

    private function utf7_ucs4array(string $input, $sc = '+'): array
    {
        $output = [];
        $outputLength = 0;
        $inputLength = $this->getByteLength($input);
        $mode = 'd';
        $b64 = '';

        for ($k = 0; $k < $inputLength; ++$k) {
            $c = $input[$k];

            // Ignore zero bytes
            if (0 === ord($c)) {
                continue;
            }
            if ('b' === $mode) {
                // Sequence got terminated
                if (!preg_match('![A-Za-z0-9/'.preg_quote($sc, '!').']!', $c)) {
                    if ('-' == $c) {
                        if ($b64 === '') {
                            $output[$outputLength] = ord($sc);
                            $outputLength++;
                            $mode = 'd';

                            continue;
                        }
                    }
                    $tmp = base64_decode($b64);
                    $tmp = substr($tmp, -1 * (strlen($tmp) % 2));
                    for ($i = 0; $i < strlen($tmp); $i++) {
                        if ($i % 2) {
                            $output[$outputLength] += ord($tmp[$i]);
                            $outputLength++;
                        } else {
                            $output[$outputLength] = ord($tmp[$i]) << 8;
                        }
                    }
                    $mode = 'd';
                    $b64 = '';

                    continue;
                } else {
                    $b64 .= $c;
                }
            }
            if ('d' === $mode) {
                if ($sc === $c) {
                    $mode = 'b';

                    continue;
                }

                $output[$outputLength] = ord($c);
                $outputLength++;
            }
        }

        return $output;
    }

    private function ucs4array_utf7imap(array $input): string
    {
        return str_replace(
            '/',
            ',',
            $this->ucs4array_utf7($input, '&')
        );
    }

    private function ucs4array_utf7(array $input, string $sc = '+'): string
    {
        $output = '';
        $mode = 'd';
        $b64 = '';
        while (true) {
            $v = (!empty($input))
                ? array_shift($input)
                : false;
            $isDirect = ! (false !== $v) || 0x20 <= $v && $v <= 0x7e && $v !== ord($sc);
            if ($mode === 'b') {
                if ($isDirect) {
                    if ($b64 === chr(0).$sc) {
                        $output .= $sc.'-';
                        $b64 = '';
                    } elseif ($b64) {
                        $output .= $sc.str_replace('=', '', base64_encode($b64)).'-';
                        $b64 = '';
                    }
                    $mode = 'd';
                } elseif (false !== $v) {
                    $b64 .= chr(($v >> 8) & 255).chr($v & 255);
                }
            }
            if ($mode === 'd' && false !== $v) {
                if ($isDirect) {
                    $output .= chr($v);
                } else {
                    $b64 = chr(($v >> 8) & 255).chr($v & 255);
                    $mode = 'b';
                }
            }
            if (false === $v && $b64 === '') {
                break;
            }
        }

        return $output;
    }

    /**
     * Convert UCS-4 array into UCS-4 string (Little Endian at the moment)
     */
    private function ucs4array_ucs4(array $input): string
    {
        $output = '';
        foreach ($input as $v) {
            $output .= sprintf(
                '%s%s%s%s',
                chr(($v >> 24) & 255),
                chr(($v >> 16) & 255),
                chr(($v >> 8) & 255),
                chr($v & 255),
            );
        }

        return $output;
    }

    /**
     * Convert UCS-4 string (LE only) to UCS-4 array
     *
     * @throws InvalidCharacterException
     */
    private function ucs4_ucs4array(string $input): array
    {
        $output = [];

        $inputLength = $this->getByteLength($input);
        // Input length must be dividable by 4
        if ($inputLength % 4) {
            throw new InvalidCharacterException('Input UCS4 string is broken', 306);
        }
        // Empty input - return empty output
        if (!$inputLength) {
            return $output;
        }

        for ($i = 0, $outputLength = -1; $i < $inputLength; ++$i) {
            if (!($i % 4)) { // Increment output position every 4 input bytes
                $outputLength++;
                $output[$outputLength] = 0;
            }
            $output[$outputLength] += ord($input[$i]) << (8 * (3 - ($i % 4)));
        }

        return $output;
    }
}
