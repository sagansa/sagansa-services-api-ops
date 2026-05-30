<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;

class QrisService
{
    /**
     * Generate a dynamic QRIS payload from a static QRIS payload and payable amount.
     */
    public function withAmount(string $payload, int|float $amount): string
    {
        $amount = round((float) $amount, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('QRIS amount must be greater than zero.');
        }

        $tags = $this->parsePayload($this->withoutCrc(trim($payload)));
        $result = '';
        $amountWritten = false;

        foreach ($tags as $tag) {
            if ($tag['id'] === '01') {
                $result .= $this->tag('01', '12');
                continue;
            }

            if ($tag['id'] === '54') {
                $result .= $this->tag('54', $this->formatAmount($amount));
                $amountWritten = true;
                continue;
            }

            if (! $amountWritten && strcmp($tag['id'], '54') > 0) {
                $result .= $this->tag('54', $this->formatAmount($amount));
                $amountWritten = true;
            }

            $result .= $this->tag($tag['id'], $tag['value']);
        }

        if (! $amountWritten) {
            $result .= $this->tag('54', $this->formatAmount($amount));
        }

        return $this->appendCrc($result);
    }

    public function toSvg(string $payload, int $size = 320): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd()
        );

        return (new Writer($renderer))->writeString($payload);
    }

    private function parsePayload(string $payload): array
    {
        $tags = [];
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            if ($offset + 4 > $length) {
                throw new InvalidArgumentException('Invalid QRIS payload.');
            }

            $id = substr($payload, $offset, 2);
            $valueLength = (int) substr($payload, $offset + 2, 2);
            $valueStart = $offset + 4;
            $value = substr($payload, $valueStart, $valueLength);

            if (strlen($value) !== $valueLength) {
                throw new InvalidArgumentException('Invalid QRIS payload length.');
            }

            $tags[] = [
                'id' => $id,
                'value' => $value,
            ];

            $offset = $valueStart + $valueLength;
        }

        return $tags;
    }

    private function withoutCrc(string $payload): string
    {
        $position = strrpos($payload, '6304');

        return $position === false ? $payload : substr($payload, 0, $position);
    }

    private function appendCrc(string $payload): string
    {
        $payloadForCrc = $payload . '6304';

        return $payloadForCrc . strtoupper(str_pad(dechex($this->crc16($payloadForCrc)), 4, '0', STR_PAD_LEFT));
    }

    private function crc16(string $payload): int
    {
        $crc = 0xFFFF;

        for ($i = 0, $length = strlen($payload); $i < $length; $i++) {
            $crc ^= ord($payload[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return $crc & 0xFFFF;
    }

    private function formatAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private function tag(string $id, string $value): string
    {
        return $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }
}
