<?php
declare(strict_types = 1);

namespace BaconQrCode\Renderer\Image;

use BaconQrCode\Exception\RuntimeException;
use BaconQrCode\Renderer\Color\Alpha;
use BaconQrCode\Renderer\Color\ColorInterface;
use BaconQrCode\Renderer\Path\Close;
use BaconQrCode\Renderer\Path\Curve;
use BaconQrCode\Renderer\Path\EllipticArc;
use BaconQrCode\Renderer\Path\Line;
use BaconQrCode\Renderer\Path\Move;
use BaconQrCode\Renderer\Path\Path;
use BaconQrCode\Renderer\RendererStyle\Gradient;
use XMLWriter;

final class SvgImageBackEnd implements ImageBackEndInterface
{
    private const PRECISION = 3;

    /**
     * @var XMLWriter|null
     */
    private $xmlWriter;

    /**
     * @var int[]|null
     */
    private $stack;

    /**
     * @var int|null
     */
    private $currentStack;

    public function __construct()
    {
        if (! class_exists(XMLWriter::class)) {
            throw new RuntimeException('You need to install the libxml extension to use this back end');
        }
    }

    public function new(int $size, ColorInterface $backgroundColor) : void
    {
        $this->xmlWriter = new XMLWriter();
        $this->xmlWriter->openMemory();

        $this->xmlWriter->startDocument('1.0', 'UTF-8');
        $this->xmlWriter->startElement('svg');
        $this->xmlWriter->writeAttribute('xmlns', 'http://www.w3.org/2000/svg');
        $this->xmlWriter->writeAttribute('version', '1.1');
        $this->xmlWriter->writeAttribute('width', $size . 'px');
        $this->xmlWriter->writeAttribute('height', $size . 'px');
        $this->xmlWriter->writeAttribute('viewBox', '0 0 '. $size . ' ' . $size);

        $this->currentStack = 0;
        $this->stack[0] = 0;

        $alpha = 1;

        if ($backgroundColor instanceof Alpha) {
            $alpha = $backgroundColor->getAlpha() / 100;
        }

        if (0 === $alpha) {
            return;
        }

        $this->xmlWriter->startElement('rect');
        $this->xmlWriter->writeAttribute('x', '0');
        $this->xmlWriter->writeAttribute('y', '0');
        $this->xmlWriter->writeAttribute('width', (string) $size);
        $this->xmlWriter->writeAttribute('height', (string) $size);
        $this->xmlWriter->writeAttribute('fill', $this->getColorString($backgroundColor));

        if ($alpha < 1) {
            $this->xmlWriter->writeAttribute('fill-opacity', (string) $alpha);
        }

        $this->xmlWriter->endElement();
    }

    public function scale(float $size) : void
    {
        if (null === $this->xmlWriter) {
            throw new RuntimeException('No image has been started');
        }

        $this->xmlWriter->startElement('g');
        $this->xmlWriter->writeAttribute(
            'transform',
            sprintf('scale(%s)', round($size, self::PRECISION))
        );
        ++$this->stack[$this->currentStack];
    }

    public function translate(float $x, float $y) : void
    {
        if (null === $this->xmlWriter) {
            throw new RuntimeException('No image has been started');
        }

        $this->xmlWriter->startElement('g');
        $this->xmlWriter->writeAttribute(
            'transform',
            sprintf('translate(%s,%s)', round($x, self::PRECISION), round($y, self::PRECISION))
        );
        ++$this->stack[$this->currentStack];
    }

    public function rotate(int $degrees) : void
    {
        if (null === $this->xmlWriter) {
            throw new RuntimeException('No image has been started');
        }

        $this->xmlWriter->startElement('g');
        $this->xmlWriter->writeAttribute('transform', sprintf('rotate(%d)', $degrees));
        ++$this->stack[$this->currentStack];
    }

    public function push() : void
    {
        if (null === $this->xmlWriter) {
            throw new RuntimeException('No image has been started');
        }

        $this->xmlWriter->startElement('g');
        $this->stack[] = 1;
        ++$this->currentStack;
    }

    public function pop() : void
    {
        if (null === $this->xmlWriter) {
            throw new RuntimeException('No image has been started');
        }

        for ($i = 0; $i < $this->stack[$this->currentStack]; ++$i) {
            $this->xmlWriter->endElement();
        }

        array_pop($this->stack);
        --$this->currentStack;
    }

    public function drawPathWithColor(Path $path, ColorInterface $color) : void
    {
        if (null === $this->xmlWriter) {
            throw new RuntimeException('No image has been started');
        }

        $alpha = 1;

        if ($color instanceof Alpha) {
            $alpha = $color->getAlpha() / 100;
        }

        if (0 === $alpha) {
            return;
        }

        $pathData = [];

        foreach ($path as $op) {
            switch (true) {
                case $op instanceof Move:
                    $pathData[] = sprintf(
                        'M%s %s',
                        round($op->getX(), self::PRECISION),
                        round($op->getY(), self::PRECISION)
                    );
                    break;

                case $op instanceof Line:
                    $pathData[] = sprintf(
                        'L%s %s',
                        round($op->getX(), self::PRECISION),
                        round($op->getY(), self::PRECISION)
                    );
                    break;

                case $op instanceof EllipticArc:
                    $pathData[] = sprintf(
                        'A%s %s %s %u %u %s %s',
                        round($op->getXRadius(), self::PRECISION),
                        round($op->getYRadius(), self::PRECISION),
                        round($op->getXAxisAngle(), self::PRECISION),
                        $op->isLargeArc(),
                        $op->isSweep(),
                        round($op->getX(), self::PRECISION),
                        round($op->getY(), self::PRECISION)
                    );
                    break;

                case $op instanceof Curve:
                    $pathData[] = sprintf(
                        'C%s %s %s %s %s %s',
                        round($op->getX1(), self::PRECISION),
                        round($op->getY1(), self::PRECISION),
                        round($op->getX2(), self::PRECISION),
                        round($op->getY2(), self::PRECISION),
                        round($op->getX3(), self::PRECISION),
                        round($op->getY3(), self::PRECISION)
                    );
                    break;

                case $op instanceof Close:
                    $pathData[] = 'Z';
                    break;

                default:
                    throw new RuntimeException('Unexpected draw operation: ' . get_class($op));
            }
        }

        $this->xmlWriter->startElement('path');
        $this->xmlWriter->writeAttribute('fill', $this->getColorString($color));
        $this->xmlWriter->writeAttribute('fill-rule', 'evenodd');
        $this->xmlWriter->writeAttribute('d', implode('', $pathData));

        if ($alpha < 1) {
            $this->xmlWriter->writeAttribute('fill-opacity', (string) $alpha);
        }

        $this->xmlWriter->endElement();
    }

    public function drawPathWithGradient(Path $path, Gradient $gradient) : void
    {
        // @todo
    }

    public function done() : string
    {
        if (null === $this->xmlWriter) {
            throw new RuntimeException('No image has been started');
        }

        foreach ($this->stack as $openElements) {
            for ($i = $openElements; $i > 0; --$i) {
                $this->xmlWriter->endElement();
            }
        }

        $this->xmlWriter->endDocument();
        $blob = $this->xmlWriter->outputMemory(true);
        $this->xmlWriter = null;
        $this->stack = null;
        $this->currentStack = null;

        return $blob;
    }

    private function getColorString(ColorInterface $color) : string
    {
        $color = $color->toRgb();

        return sprintf(
            '#%02x%02x%02x',
            $color->getRed(),
            $color->getGreen(),
            $color->getBlue()
        );
    }
}
