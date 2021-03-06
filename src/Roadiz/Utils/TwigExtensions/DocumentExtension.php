<?php
/**
 * Copyright © 2015, Ambroise Maupate and Julien Blanchet
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
 * OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * Except as contained in this notice, the name of the ROADIZ shall not
 * be used in advertising or otherwise to promote the sale, use or other dealings
 * in this Software without prior written authorization from Ambroise Maupate and Julien Blanchet.
 *
 * @file DocumentExtension.php
 * @author Ambroise Maupate
 */
namespace RZ\Roadiz\Utils\TwigExtensions;

use Intervention\Image\Exception\NotReadableException;
use Intervention\Image\ImageManager;
use Pimple\Container;
use RZ\Roadiz\Core\Entities\Document;
use RZ\Roadiz\Core\Viewers\DocumentViewer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\OptionsResolver\Exception\InvalidArgumentException;

/**
 * Extension that allow render document images.
 */
class DocumentExtension extends \Twig_Extension
{
    /**
     * @var Container
     */
    private $container;
    /**
     * @var bool
     */
    private $throwExceptions;

    /**
     * DocumentExtension constructor.
     * @param Container $container
     * @param bool $throwExceptions Trigger exception if using filter on NULL values (default: false)
     */
    public function __construct(Container $container, $throwExceptions = false)
    {
        $this->container = $container;
        $this->throwExceptions = $throwExceptions;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'documentExtension';
    }

    /**
     * @return array
     */
    public function getFilters()
    {
        return [
            new \Twig_SimpleFilter('display', [$this, 'display'], ['is_safe' => ['html']]),
            new \Twig_SimpleFilter('imageRatio', [$this, 'getImageRatio']),
            new \Twig_SimpleFilter('imageSize', [$this, 'getImageSize']),
            new \Twig_SimpleFilter('imageOrientation', [$this, 'getImageOrientation']),
            new \Twig_SimpleFilter('path', [$this, 'getPath']),
            new \Twig_SimpleFilter('exists', [$this, 'exists']),
            new \Twig_SimpleFilter('embedFinder', [$this, 'getEmbedFinder']),
        ];
    }

    /**
     * @param Document|null $document
     * @return bool|\RZ\Roadiz\Utils\MediaFinders\AbstractEmbedFinder
     * @throws \Twig_Error_Runtime
     */
    public function getEmbedFinder(Document $document = null)
    {
        if (null === $document) {
            if ($this->throwExceptions) {
                throw new \Twig_Error_Runtime('Document can’t be null to get its EmbedFinder.');
            } else {
                return false;
            }
        }

        /** @var DocumentViewer $documentViewer */
        $documentViewer = $this->container->offsetGet('document.viewer');
        $documentViewer->setDocument($document);
        return $documentViewer->getEmbedFinder();
    }

    /**
     * @param Document|null $document
     * @param array $criteria
     * @return string
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public function display(Document $document = null, array $criteria = [])
    {
        if (null === $document) {
            if ($this->throwExceptions) {
                throw new \Twig_Error_Runtime('Document can’t be null to be displayed.');
            } else {
                return "";
            }
        }
        try {
            /** @var DocumentViewer $documentViewer */
            $documentViewer = $this->container->offsetGet('document.viewer');
            $documentViewer->setDocument($document);
            return $documentViewer->getDocumentByArray($criteria);
        } catch (InvalidArgumentException $e) {
            throw new \Twig_Error_Runtime($e->getMessage(), -1, null, $e);
        }
    }

    /**
     * Get image orientation.
     *
     * - Return null if document is not an Image
     * - Return `'landscape'` if width is higher or equal to height
     * - Return `'portrait'` if height is strictly lower to width
     *
     * @param Document $document
     * @return null|string
     * @throws \Twig_Error_Runtime
     */
    public function getImageOrientation(Document $document = null)
    {
        if (null === $document) {
            if ($this->throwExceptions) {
                throw new \Twig_Error_Runtime('Document can’t be null to get its orientation.');
            } else {
                return null;
            }
        }
        if (null !== $document && $document->isImage()) {
            $size = $this->getImageSize($document);
            return $size['width'] >= $size['height'] ? 'landscape' : 'portrait';
        }

        return null;
    }

    /**
     * @param Document $document
     * @return array
     * @throws \Twig_Error_Runtime
     */
    public function getImageSize(Document $document = null)
    {
        if (null === $document) {
            if ($this->throwExceptions) {
                throw new \Twig_Error_Runtime('Document can’t be null to get its size.');
            } else {
                return [
                    'width' => 0,
                    'height' => 0,
                ];
            }
        }
        if (null !== $document && $document->isImage()) {
            try {
                $manager = new ImageManager();
                $documentPath = $this->container['assetPackages']->getDocumentFilePath($document);
                $imageProcess = $manager->make($documentPath);
                return [
                    'width' => $imageProcess->width(),
                    'height' => $imageProcess->height(),
                ];
            } catch (NotReadableException $exception) {
                /*
                 * Do nothing
                 * just return 0 width and height
                 */
            }
        }

        return [
            'width' => 0,
            'height' => 0,
        ];
    }

    /**
     * @param Document $document
     * @return float
     * @throws \Twig_Error_Runtime
     */
    public function getImageRatio(Document $document = null)
    {
        if (null === $document) {
            if ($this->throwExceptions) {
                throw new \Twig_Error_Runtime('Document can’t be null to get its ratio.');
            } else {
                return 0.0;
            }
        }

        if (null !== $document && $document->isImage()) {
            $size = $this->getImageSize($document);
            if ($size['height'] > 0) {
                return $size['width']/$size['height'];
            }
        }

        return 0.0;
    }

    /**
     * @param Document|null $document
     * @return null|string
     */
    public function getPath(Document $document = null)
    {
        if (null !== $document) {
            return $this->container['assetPackages']->getDocumentFilePath($document);
        }

        return null;
    }

    /**
     * @param Document|null $document
     * @return bool
     */
    public function exists(Document $document = null)
    {
        if (null !== $document) {
            $fs = new Filesystem();
            return $fs->exists($this->container['assetPackages']->getDocumentFilePath($document));
        }

        return false;
    }
}
