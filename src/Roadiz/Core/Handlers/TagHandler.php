<?php
/**
 * Copyright © 2014, Ambroise Maupate and Julien Blanchet
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
 * @file TagHandler.php
 * @author Ambroise Maupate
 */
namespace RZ\Roadiz\Core\Handlers;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\NoResultException;
use RZ\Roadiz\Core\Entities\Tag;

/**
 * Handle operations with tags entities.
 */
class TagHandler extends AbstractHandler
{
    private $tag;

    /**
     * @return Tag
     */
    public function getTag()
    {
        return $this->tag;
    }

    /**
     * @param Tag $tag
     * @return $this
     */
    public function setTag(Tag $tag)
    {
        $this->tag = $tag;
        return $this;
    }
    /**
     * Create a new tag handler with tag to handle.
     *
     * @param Tag|null $tag
     */
    public function __construct(Tag $tag = null)
    {
        parent::__construct();
        $this->tag = $tag;
    }

    /**
     * Remove only current tag children.
     *
     * @return $this
     */
    private function removeChildren()
    {
        foreach ($this->tag->getChildren() as $tag) {
            $tag->getHandler()->removeWithChildrenAndAssociations();
        }

        return $this;
    }
    /**
     * Remove only current tag associations.
     *
     * @return $this
     */
    public function removeAssociations()
    {
        foreach ($this->tag->getTranslatedTags() as $tt) {
            $this->entityManager->remove($tt);
        }

        return $this;
    }
    /**
     * Remove current tag with its children recursively and
     * its associations.
     *
     * @return $this
     */
    public function removeWithChildrenAndAssociations()
    {
        $this->removeChildren();
        $this->removeAssociations();

        $this->entityManager->remove($this->tag);

        /*
         * Final flush
         */
        $this->entityManager->flush();

        return $this;
    }

    /**
     * @return array Array of Translation
     */
    public function getAvailableTranslations()
    {
        $query = $this->entityManager
                        ->createQuery('
            SELECT tr
            FROM RZ\Roadiz\Core\Entities\Translation tr
            INNER JOIN tr.tagTranslations tt
            INNER JOIN tt.tag t
            WHERE t.id = :tag_id')
                        ->setParameter('tag_id', $this->tag->getId());

        try {
            return $query->getResult();
        } catch (NoResultException $e) {
            return null;
        }
    }
    /**
     * @return array Array of Translation id
     */
    public function getAvailableTranslationsId()
    {
        $query = $this->entityManager
                        ->createQuery('
            SELECT tr.id FROM RZ\Roadiz\Core\Entities\Tag t
            INNER JOIN t.translatedTags tt
            INNER JOIN tt.translation tr
            WHERE t.id = :tag_id')
                        ->setParameter('tag_id', $this->tag->getId());

        try {
            $simpleArray = [];
            $complexArray = $query->getScalarResult();
            foreach ($complexArray as $subArray) {
                $simpleArray[] = $subArray['id'];
            }

            return $simpleArray;
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * @return array Array of Translation
     */
    public function getUnavailableTranslations()
    {
        $query = $this->entityManager
                        ->createQuery('
            SELECT tr FROM RZ\Roadiz\Core\Entities\Translation tr
            WHERE tr.id NOT IN (:translations_id)')
                        ->setParameter('translations_id', $this->getAvailableTranslationsId());

        try {
            return $query->getResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * @return array Array of Translation id
     */
    public function getUnavailableTranslationsId()
    {
        $query = $this->entityManager
                        ->createQuery('
            SELECT t.id FROM RZ\Roadiz\Core\Entities\Translation t
            WHERE t.id NOT IN (:translations_id)')
                        ->setParameter('translations_id', $this->getAvailableTranslationsId());

        try {
            $simpleArray = [];
            $complexArray = $query->getScalarResult();
            foreach ($complexArray as $subArray) {
                $simpleArray[] = $subArray['id'];
            }

            return $simpleArray;
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * Return every tag’s parents.
     * @deprecated Use directly Tag::getParents
     * @return array
     */
    public function getParents()
    {
        $parentsArray = [];
        $parent = $this->tag;

        do {
            $parent = $parent->getParent();
            if ($parent !== null) {
                $parentsArray[] = $parent;
            } else {
                break;
            }
        } while ($parent !== null);

        return array_reverse($parentsArray);
    }

    /**
     * Get tag full path using tag names.
     *
     * @deprecated Use directly Tag::getFullPath
     * @return string
     */
    public function getFullPath()
    {
        $parents = $this->getParents();
        $path = [];

        foreach ($parents as $parent) {
            $path[] = $parent->getTagName();
        }

        $path[] = $this->tag->getTagName();

        return implode('/', $path);
    }

    /**
     * Clean position for current tag siblings.
     *
     * @param bool $setPositions
     * @return int Return the next position after the **last** tag
     */
    public function cleanPositions($setPositions = true)
    {
        if ($this->tag->getParent() !== null) {
            $tagHandler = new TagHandler();
            $tagHandler->setTag($this->tag->getParent());
            return $tagHandler->cleanChildrenPositions($setPositions);
        } else {
            return $this->cleanRootTagsPositions($setPositions);
        }
    }

    /**
     * Reset current tag children positions.
     *
     * Warning, this method does not flush.
     *
     * @param bool $setPositions
     * @return int Return the next position after the **last** tag
     */
    public function cleanChildrenPositions($setPositions = true)
    {
        /*
         * Force collection to sort on position
         */
        $sort = Criteria::create();
        $sort->orderBy([
            'position' => Criteria::ASC
        ]);

        $children = $this->tag->getChildren()->matching($sort);
        $i = 1;
        foreach ($children as $child) {
            if ($setPositions) {
                $child->setPosition($i);
            }
            $i++;
        }

        return $i;
    }

    /**
     * Reset every root tags positions.
     *
     * Warning, this method does not flush.
     *
     * @param bool $setPositions
     * @return int Return the next position after the **last** tag
     */
    public function cleanRootTagsPositions($setPositions = true)
    {
        $tags = $this->entityManager
            ->getRepository('RZ\Roadiz\Core\Entities\Tag')
            ->findBy(['parent' => null], ['position'=>'ASC']);

        $i = 1;
        /** @var Tag $child */
        foreach ($tags as $child) {
            if ($setPositions) {
                $child->setPosition($i);
            }
            $i++;
        }

        return $i;
    }
}
