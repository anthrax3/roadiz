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
 * @file NodeRepository.php
 * @author Ambroise Maupate
 */
namespace RZ\Roadiz\Core\Repositories;

use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use RZ\Roadiz\Core\Entities\Node;
use RZ\Roadiz\Core\Entities\NodesSources;
use RZ\Roadiz\Core\Entities\NodeTypeField;
use RZ\Roadiz\Core\Entities\Translation;
use RZ\Roadiz\Core\Entities\UrlAlias;

/**
 * NodeRepository
 */
class NodeRepository extends StatusAwareRepository
{
    /**
     * Just like the countBy method but with relational criteria.
     *
     * @param array $criteria
     * @param Translation|null $translation
     * @return int
     */
    public function countBy(
        $criteria,
        Translation $translation = null
    ) {
        $query = $this->getCountContextualQueryWithTranslation(
            $criteria,
            $translation
        );

        $this->dispatchQueryBuilderEvent($query, $this->getEntityName());
        $finalQuery = $query->getQuery();
        $this->applyFilterByTag($criteria, $finalQuery);
        $this->applyFilterByCriteria($criteria, $finalQuery);
        $this->applyTranslationByTag($finalQuery, $translation);

        try {
            return (int) $finalQuery->getSingleScalarResult();
        } catch (NoResultException $e) {
            return 0;
        }
    }

    /**
     * Create a secured count query with node.published = true if user is
     * not a Backend user and if authorizationChecker is defined.
     *
     * This method allows to pre-filter Nodes with a given translation.
     *
     * @param array $criteria
     * @param Translation|null $translation
     * @return QueryBuilder
     */
    protected function getCountContextualQueryWithTranslation(
        array &$criteria,
        Translation $translation = null
    ) {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select($qb->expr()->countDistinct('n.id'));

        $this->filterByTranslation($criteria, $qb, $translation);
        /*
         * Filtering by tag
         */
        $this->filterByTag($criteria, $qb);
        $this->filterByCriteria($criteria, $qb);
        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        return $qb;
    }

    /**
     * Create filters according to any translation criteria OR argument.
     *
     * @param array            $criteria
     * @param QueryBuilder     $qb
     * @param Translation|null $translation
     */
    protected function filterByTranslation(&$criteria, &$qb, &$translation = null)
    {
        if (isset($criteria['translation']) ||
            isset($criteria['translation.locale']) ||
            isset($criteria['translation.id']) ||
            isset($criteria['translation.available'])) {
            $qb->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS);
            $qb->innerJoin('ns.translation', static::TRANSLATION_ALIAS);
        } else {
            if (null !== $translation) {
                /*
                 * With a given translation
                 */
                $qb->innerJoin(
                    'n.nodeSources',
                    static::NODESSOURCES_ALIAS,
                    'WITH',
                    'ns.translation = :translation'
                );
            } else {
                /*
                 * With a null translation, not filter by translation to enable
                 * nodes with only one translation which is not the default one.
                 */
                $qb->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS);
            }
        }
    }

    /**
     * Add a tag filtering to queryBuilder.
     *
     * @param array        $criteria
     * @param QueryBuilder $qb
     */
    protected function filterByTag(&$criteria, &$qb)
    {
        if (in_array('tags', array_keys($criteria))) {
            $this->buildTagFiltering($criteria, $qb);
        }
    }

    /**
     * Reimplementing findBy features… with extra things.
     *
     * * key => array('<=', $value)
     * * key => array('<', $value)
     * * key => array('>=', $value)
     * * key => array('>', $value)
     * * key => array('BETWEEN', $value, $value)
     * * key => array('LIKE', $value)
     * * key => array('NOT IN', $array)
     * * key => 'NOT NULL'
     *
     * You can filter with translations relation, examples:
     *
     * * `translation => $object`
     * * `translation.locale => 'fr_FR'`
     *
     * @param array        $criteria
     * @param QueryBuilder $qb
     */
    protected function filterByCriteria(&$criteria, &$qb)
    {
        /*
         * Reimplementing findBy features…
         */
        foreach ($criteria as $key => $value) {
            if ($key == "tags" || $key == "tagExclusive") {
                continue;
            }

            /*
             * compute prefix for
             * filtering node, and sources relation fields
             */
            $prefix = static::NODE_ALIAS . '.';

            // Dots are forbidden in field definitions
            $baseKey = str_replace('.', '_', $key);
            /*
             * Search in translation fields
             */
            if (false !== strpos($key, 'translation.')) {
                $prefix = static::TRANSLATION_ALIAS . '.';
                $key = str_replace('translation.', '', $key);
            }
            /*
             * Search in nodeSource fields
             */
            if ($key == 'translation') {
                $prefix = static::NODESSOURCES_ALIAS . '.';
            }

            $qb->andWhere($this->buildComparison($value, $prefix, $key, $baseKey, $qb));
        }
    }

    /**
     * Bind parameters to generated query.
     *
     * @param array $criteria
     * @param Query $finalQuery
     */
    protected function applyFilterByCriteria(&$criteria, &$finalQuery)
    {
        /*
         * Reimplementing findBy features…
         */
        foreach ($criteria as $key => $value) {
            if ($key == "tags" || $key == "tagExclusive") {
                continue;
            }

            $this->applyComparison($key, $value, $finalQuery);
        }
    }

    /**
     * Bind translation parameter to final query.
     *
     * @param Query $finalQuery
     * @param Translation|null $translation
     */
    protected function applyTranslationByTag(
        Query $finalQuery,
        Translation $translation = null
    ) {
        if (null !== $translation) {
            $finalQuery->setParameter('translation', $translation);
        }
    }

    /**
     * Just like the findBy method but with a given Translation
     *
     * If no translation nor authorizationChecker is given, the vanilla `findBy`
     * method will be called instead.
     *
     * @param array $criteria
     * @param array|null $orderBy
     * @param integer|null $limit
     * @param integer|null $offset
     * @param Translation|null $translation
     * @return array
     */
    public function findByWithTranslation(
        array $criteria,
        array $orderBy = null,
        $limit = null,
        $offset = null,
        Translation $translation = null
    ) {
        return $this->findBy(
            $criteria,
            $orderBy,
            $limit,
            $offset,
            $translation
        );
    }

    /**
     * Just like the findBy method but with relational criteria.
     *
     * Reimplementing findBy features… with extra things:
     *
     * * key => array('<=', $value)
     * * key => array('<', $value)
     * * key => array('>=', $value)
     * * key => array('>', $value)
     * * key => array('BETWEEN', $value, $value)
     * * key => array('LIKE', $value)
     * * key => array('NOT IN', $array)
     * * key => 'NOT NULL'
     *
     * You can filter with translations relation, examples:
     *
     * * `translation => $object`
     * * `translation.locale => 'fr_FR'`
     *
     * Or filter by tags:
     *
     * * `tags => $tag1`
     * * `tags => [$tag1, $tag2]`
     * * `tags => [$tag1, $tag2], tagExclusive => true`
     *
     * @param array $criteria
     * @param array|null $orderBy
     * @param integer|null $limit
     * @param integer|null $offset
     * @param Translation|null $translation
     * @return array|Paginator
     */
    public function findBy(
        array $criteria,
        array $orderBy = null,
        $limit = null,
        $offset = null,
        Translation $translation = null
    ) {
        $query = $this->getContextualQueryWithTranslation(
            $criteria,
            $orderBy,
            $limit,
            $offset,
            $translation
        );

        $query->setCacheable(true);
        $this->dispatchQueryBuilderEvent($query, $this->getEntityName());
        $finalQuery = $query->getQuery();

        $this->applyFilterByTag($criteria, $finalQuery);
        $this->applyFilterByCriteria($criteria, $finalQuery);
        $this->applyTranslationByTag($finalQuery, $translation);

        if (null !== $limit &&
            null !== $offset) {
            /*
             * We need to use Doctrine paginator
             * if a limit is set because of the default inner join
             */
            return new Paginator($finalQuery);
        } else {
            try {
                return $finalQuery->getResult();
            } catch (NoResultException $e) {
                return [];
            }
        }
    }

    /**
     * Create a securized query with node.published = true if user is
     * not a Backend user and if authorizationChecker is defined.
     *
     * This method allows to pre-filter Nodes with a given translation.
     *
     * @param array $criteria
     * @param array|null $orderBy
     * @param integer|null $limit
     * @param integer|null $offset
     * @param Translation|null $translation
     * @return QueryBuilder
     */
    protected function getContextualQueryWithTranslation(
        array &$criteria,
        array &$orderBy = null,
        $limit = null,
        $offset = null,
        Translation $translation = null
    ) {

        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->addSelect(static::NODESSOURCES_ALIAS);

        $this->filterByTranslation($criteria, $qb, $translation);

        /*
         * Filtering by tag
         */
        $this->filterByTag($criteria, $qb);
        $this->filterByCriteria($criteria, $qb);
        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        // Add ordering
        if (null !== $orderBy) {
            foreach ($orderBy as $key => $value) {
                if (strpos($key, static::NODESSOURCES_ALIAS . '.') === 0) {
                    $qb->addOrderBy($key, $value);
                } else {
                    $qb->addOrderBy(static::NODE_ALIAS . '.' . $key, $value);
                }
            }
        }
        if (null !== $offset) {
            $qb->setFirstResult($offset);
        }
        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb;
    }

    /**
     * Just like the findOneBy method but with a given Translation and optional
     * AuthorizationChecker.
     *
     * If no translation nor authorizationChecker is given, the vanilla `findOneBy`
     * method will be called instead.
     *
     * @param array $criteria
     * @param Translation|null $translation
     * @return null|Node
     */
    public function findOneByWithTranslation(
        array $criteria,
        Translation $translation = null
    ) {
        return $this->findOneBy(
            $criteria,
            null,
            $translation
        );
    }

    /**
     * Just like the findOneBy method but with relational criteria.
     *
     * @param array $criteria
     * @param array|null $orderBy
     * @param Translation|null $translation
     * @return null|Node
     */
    public function findOneBy(
        array $criteria,
        array $orderBy = null,
        Translation $translation = null
    ) {

        $query = $this->getContextualQueryWithTranslation(
            $criteria,
            $orderBy,
            1,
            0,
            $translation
        );

        $query->setCacheable(true);
        $this->dispatchQueryBuilderEvent($query, $this->getEntityName());
        $finalQuery = $query->getQuery();

        $this->applyFilterByTag($criteria, $finalQuery);
        $this->applyFilterByCriteria($criteria, $finalQuery);
        $this->applyTranslationByTag($finalQuery, $translation);

        try {
            return $finalQuery->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * Find one Node with its Id and a given translation.
     *
     * @param integer $nodeId
     * @param Translation $translation
     * @return null|Node
     */
    public function findWithTranslation(
        $nodeId,
        Translation $translation
    ) {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select('n, ns')
            ->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS)
            ->andWhere($qb->expr()->eq('n.id', ':nodeId'))
            ->andWhere($qb->expr()->eq('ns.translation', ':translation'))
            ->setMaxResults(1)
            ->setParameter('nodeId', (int) $nodeId)
            ->setParameter('translation', $translation)
            ->setCacheable(true);

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        try {
            return $qb->getQuery()->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * Find one Node with its Id and the default translation.
     *
     * @param integer $nodeId
     * @return null|Node
     */
    public function findWithDefaultTranslation(
        $nodeId
    ) {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select('n, ns')
            ->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS)
            ->innerJoin('ns.translation', static::TRANSLATION_ALIAS)
            ->andWhere($qb->expr()->eq('n.id', ':nodeId'))
            ->andWhere($qb->expr()->eq('t.defaultTranslation', ':defaultTranslation'))
            ->setMaxResults(1)
            ->setParameter('nodeId', (int) $nodeId)
            ->setParameter('defaultTranslation', true)
            ->setCacheable(true);

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        try {
            return $qb->getQuery()->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * Find one Node with its nodeName and a given translation.
     *
     * @param string $nodeName
     * @param Translation $translation
     * @return null|Node
     */
    public function findByNodeNameWithTranslation(
        $nodeName,
        Translation $translation
    ) {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select('n, ns')
            ->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS)
            ->andWhere($qb->expr()->eq('n.nodeName', ':nodeName'))
            ->andWhere($qb->expr()->eq('ns.translation', ':translation'))
            ->setMaxResults(1)
            ->setParameter('nodeName', $nodeName)
            ->setParameter('translation', $translation)
            ->setCacheable(true);

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        try {
            return $qb->getQuery()->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * Find one Node with its nodeName and the default translation.
     *
     * @param string $nodeName
     * @return null|Node
     */
    public function findByNodeNameWithDefaultTranslation(
        $nodeName
    ) {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select('n, ns')
            ->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS)
            ->innerJoin('ns.translation', static::TRANSLATION_ALIAS)
            ->andWhere($qb->expr()->eq('n.nodeName', ':nodeName'))
            ->andWhere($qb->expr()->eq('t.defaultTranslation', ':defaultTranslation'))
            ->setMaxResults(1)
            ->setParameter('nodeName', $nodeName)
            ->setParameter('defaultTranslation', true)
            ->setCacheable(true);

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        try {
            return $qb->getQuery()->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * Find the Home node with a given translation.
     *
     * @param Translation|null $translation
     * @return null|Node
     */
    public function findHomeWithTranslation(
        Translation $translation = null
    ) {
        if (null === $translation) {
            return $this->findHomeWithDefaultTranslation();
        }

        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select('n, ns')
            ->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS)
            ->andWhere($qb->expr()->eq('n.home', ':home'))
            ->andWhere($qb->expr()->eq('ns.translation', ':translation'))
            ->setMaxResults(1)
            ->setParameter('home', true)
            ->setParameter('translation', $translation)
            ->setCacheable(true);

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        try {
            return $qb->getQuery()->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * Find the Home node with the default translation.
     *
     * @return null|Node
     */
    public function findHomeWithDefaultTranslation()
    {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select('n, ns')
            ->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS)
            ->innerJoin('ns.translation', static::TRANSLATION_ALIAS)
            ->andWhere($qb->expr()->eq('n.home', ':home'))
            ->andWhere($qb->expr()->eq('t.defaultTranslation', ':defaultTranslation'))
            ->setMaxResults(1)
            ->setParameter('home', true)
            ->setParameter('defaultTranslation', true)
            ->setCacheable(true);

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        try {
            return $qb->getQuery()->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * @param Node $node
     * @param Translation $translation
     * @return array
     * @deprecated Use findByParentWithTranslation instead
     */
    public function getChildrenWithTranslation(
        Node $node,
        Translation $translation
    ) {
        return $this->findByParentWithTranslation($translation, $node);
    }

    /**
     * @param Translation $translation
     * @param Node|null $parent
     * @return array
     */
    public function findByParentWithTranslation(
        Translation $translation,
        Node $parent = null
    ) {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select('n, ns, ua')
            ->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS)
            ->leftJoin(static::NODESSOURCES_ALIAS.'.urlAliases', 'ua')
            ->andWhere($qb->expr()->eq('ns.translation', ':translation'))
            ->setParameter('translation', $translation)
            ->addOrderBy('n.position', 'ASC')
            ->setCacheable(true);

        if ($parent === null) {
            $qb->andWhere($qb->expr()->isNull('n.parent'));
        } else {
            $qb->andWhere($qb->expr()->eq('n.parent', ':parent'))
                ->setParameter('parent', $parent);
        }

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        try {
            return $qb->getQuery()->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * @param Node|null $parent
     * @return Node[]
     */
    public function findByParentWithDefaultTranslation(Node $parent = null)
    {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select('n, ns')
            ->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS)
            ->innerJoin('ns.translation', static::TRANSLATION_ALIAS)
            ->andWhere($qb->expr()->eq('t.defaultTranslation', true))
            ->addOrderBy('n.position', 'ASC')
            ->setCacheable(true);

        if ($parent === null) {
            $qb->andWhere($qb->expr()->isNull('n.parent'));
        } else {
            $qb->andWhere($qb->expr()->eq('n.parent', ':parent'))
                ->setParameter('parent', $parent);
        }

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        try {
            return $qb->getQuery()->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * @param UrlAlias $urlAlias
     * @return null|Node
     */
    public function findOneWithUrlAlias(UrlAlias $urlAlias)
    {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select('n, ns')
            ->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS)
            ->andWhere($qb->expr()->eq('ns.urlAliases', ':urlAlias'))
            ->setParameter('urlAlias', $urlAlias)
            ->setMaxResults(1)
            ->setCacheable(true);

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        try {
            return $qb->getQuery()->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * @param string $urlAliasAlias
     * @return null|Node
     */
    public function findOneWithAliasAndAvailableTranslation($urlAliasAlias)
    {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select('n, ns, t, uas')
            ->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS)
            ->innerJoin('ns.urlAliases', 'uas')
            ->innerJoin('ns.translation', static::TRANSLATION_ALIAS)
            ->andWhere($qb->expr()->eq('uas.alias', ':alias'))
            ->andWhere($qb->expr()->eq('t.available', ':available'))
            ->setParameter('alias', $urlAliasAlias)
            ->setParameter('available', true)
            ->setMaxResults(1)
            ->setCacheable(true);

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        try {
            return $qb->getQuery()->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * @param $nodeName
     * @return bool
     */
    public function exists($nodeName)
    {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select($qb->expr()->countDistinct('n.nodeName'))
            ->andWhere($qb->expr()->eq('n.nodeName', ':nodeName'))
            ->setParameter('nodeName', $nodeName);

        try {
            return (boolean) $qb->getQuery()->getSingleScalarResult();
        } catch (NoResultException $e) {
            return false;
        }
    }

    /**
     * @param Node $node
     * @param NodeTypeField $field
     * @return Node[]
     */
    public function findByNodeAndField(
        Node $node,
        NodeTypeField $field
    ) {
        return $this->findByNodeAndFieldName(
            $node,
            $field->getName()
        );
    }

    /**
     * @param Node $node
     * @param string $fieldName
     * @return Node[]
     */
    public function findByNodeAndFieldName(
        Node $node,
        $fieldName
    ) {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select(static::NODE_ALIAS)
            ->innerJoin('n.aNodes', 'ntn')
            ->innerJoin('ntn.field', 'f')
            ->andWhere($qb->expr()->eq('f.name', ':name'))
            ->andWhere($qb->expr()->eq('ntn.nodeA', ':nodeA'))
            ->addOrderBy('ntn.position', 'ASC')
            ->setCacheable(true);

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        $qb->setParameter('name', $fieldName)
            ->setParameter('nodeA', $node);

        try {
            return $qb->getQuery()->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * @param Node $node
     * @param NodeTypeField $field
     * @param Translation $translation
     * @return array|null
     */
    public function findByNodeAndFieldAndTranslation(
        Node $node,
        NodeTypeField $field,
        Translation $translation
    ) {
        return $this->findByNodeAndFieldNameAndTranslation(
            $node,
            $field->getName(),
            $translation
        );
    }

    /**
     * @param Node $node
     * @param $fieldName
     * @param Translation $translation
     * @return array|null
     */
    public function findByNodeAndFieldNameAndTranslation(
        Node $node,
        $fieldName,
        Translation $translation
    ) {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select('n, ns')
            ->innerJoin('n.aNodes', 'ntn')
            ->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS)
            ->innerJoin('ntn.field', 'f')
            ->andWhere($qb->expr()->eq('f.name', ':name'))
            ->andWhere($qb->expr()->eq('ntn.nodeA', ':nodeA'))
            ->andWhere($qb->expr()->eq('ns.translation', ':translation'))
            ->addOrderBy('ntn.position', 'ASC')
            ->setCacheable(true);

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        $qb->setParameter('name', $fieldName)
            ->setParameter('nodeA', $node)
            ->setParameter('translation', $translation);

        try {
            return $qb->getQuery()->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * @param Node $node
     * @param NodeTypeField $field
     * @return array
     */
    public function findByReverseNodeAndField(
        Node $node,
        NodeTypeField $field
    ) {
        return $this->findByReverseNodeAndFieldName(
            $node,
            $field->getName()
        );
    }

    /**
     * @param Node $node
     * @param string $fieldName
     * @return array
     */
    public function findByReverseNodeAndFieldName(
        Node $node,
        $fieldName
    ) {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select(static::NODE_ALIAS)
            ->innerJoin('n.bNodes', 'ntn')
            ->innerJoin('ntn.field', 'f')
            ->andWhere($qb->expr()->eq('f.name', ':name'))
            ->andWhere($qb->expr()->eq('ntn.nodeB', ':nodeB'))
            ->addOrderBy('ntn.position', 'ASC')
            ->setCacheable(true);

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        $qb->setParameter('name', $fieldName)
            ->setParameter('nodeB', $node);

        try {
            return $qb->getQuery()->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * @param Node $node
     * @param NodeTypeField $field
     * @param Translation $translation
     * @return array|null
     */
    public function findByReverseNodeAndFieldAndTranslation(
        Node $node,
        NodeTypeField $field,
        Translation $translation
    ) {
        return $this->findByReverseNodeAndFieldNameAndTranslation(
            $node,
            $field->getName(),
            $translation
        );
    }

    /**
     * @param Node $node
     * @param $fieldName
     * @param Translation $translation
     * @return array|null
     */
    public function findByReverseNodeAndFieldNameAndTranslation(
        Node $node,
        $fieldName,
        Translation $translation
    ) {
        $qb = $this->createQueryBuilder(static::NODE_ALIAS);
        $qb->select('n, ns')
            ->innerJoin('n.bNodes', 'ntn')
            ->innerJoin('n.nodeSources', static::NODESSOURCES_ALIAS)
            ->innerJoin('ntn.field', 'f')
            ->andWhere($qb->expr()->eq('f.name', ':name'))
            ->andWhere($qb->expr()->eq('ns.translation', ':translation'))
            ->andWhere($qb->expr()->eq('ntn.nodeB', ':nodeB'))
            ->addOrderBy('ntn.position', 'ASC')
            ->setCacheable(true);

        $this->alterQueryBuilderWithAuthorizationChecker($qb);

        $qb->setParameter('name', $fieldName)
            ->setParameter('translation', $translation)
            ->setParameter('nodeB', $node);

        try {
            return $qb->getQuery()->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * @param Node $node
     * @return array
     */
    public function findAllOffspringIdByNode(Node $node)
    {
        $theOffprings = [];
        $in = [$node->getId()];

        do {
            $theOffprings = array_merge($theOffprings, $in);
            $subQb = $this->createQueryBuilder('n');
            $subQb->select('n.id')
                  ->andWhere($subQb->expr()->in('n.parent', ':tab'))
                  ->setParameter('tab', $in)
                  ->setCacheable(true);
            $result = $subQb->getQuery()->getScalarResult();
            $in = [];

            //For memory optimizations
            foreach ($result as $item) {
                $in[] = (int) $item['id'];
            }
        } while (!empty($in));
        return $theOffprings;
    }

    /**
     * Find all node’ parents with criteria and ordering.
     *
     * @param Node $node
     * @param array $criteria
     * @param array|null $orderBy
     * @param integer $limit
     * @param integer $offset
     * @param Translation|null $translation
     * @return array|null
     */
    public function findAllNodeParentsBy(
        Node $node,
        array $criteria,
        array $orderBy = null,
        $limit = null,
        $offset = null,
        Translation $translation = null
    ) {

        $parentsId = $this->findAllParentsIdByNode($node);
        if (count($parentsId) > 0) {
            $criteria['id'] = $parentsId;
        } else {
            return null;
        }

        return $this->findBy(
            $criteria,
            $orderBy,
            $limit,
            $offset,
            $translation
        );
    }

    /**
     * @param Node $node
     *
     * @return array
     */
    public function findAllParentsIdByNode(Node $node)
    {
        $theParents = [];
        $parent = $node->getParent();

        while (null !== $parent) {
            $theParents[] = $parent->getId();
            $parent = $parent->getParent();
        }

        return $theParents;
    }

    /**
     * @param Node $node
     * @deprecated This method should be called from Translation repository.
     * @return array
     */
    public function findAvailableTranslationForNode(Node $node)
    {
        $qb = $this->_em->createQueryBuilder();
        $qb->select(static::TRANSLATION_ALIAS)
            ->from(Translation::class, static::TRANSLATION_ALIAS)
            ->innerJoin('t.nodeSources', static::NODESSOURCES_ALIAS)
            ->innerJoin('ns.node', static::NODE_ALIAS)
            ->andWhere($qb->expr()->eq('n.id', ':nodeId'))
            ->setParameter('nodeId', $node->getId())
            ->setCacheable(true);

        try {
            return $qb->getQuery()->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * @param Node $node
     * @deprecated This method should be called from Translation repository.
     * @return array
     */
    public function findUnavailableTranslationForNode(Node $node)
    {
        $qb = $this->_em->createQueryBuilder();
        $qb->select(static::TRANSLATION_ALIAS)
            ->from(Translation::class, static::TRANSLATION_ALIAS)
            ->andWhere($qb->expr()->notIn('t.id', ':translationsId'))
            ->setParameter('translationsId', $this->findAvailableTranslationIdForNode($node))
            ->setCacheable(true);

        try {
            return $qb->getQuery()->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * @param Node $node
     * @deprecated This method should be called from Translation repository.
     * @return array
     */
    public function findAvailableTranslationIdForNode(Node $node)
    {
        $qb = $this->_em->createQueryBuilder();
        $qb->select('t.id')
            ->from(Translation::class, static::TRANSLATION_ALIAS)
            ->innerJoin('t.nodeSources', static::NODESSOURCES_ALIAS)
            ->innerJoin('ns.node', static::NODE_ALIAS)
            ->andWhere($qb->expr()->eq('n.id', ':nodeId'))
            ->setParameter('nodeId', $node->getId())
            ->setCacheable(true);

        try {
            $complexArray = $qb->getQuery()->getScalarResult();
            return array_map('current', $complexArray);
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * @param Node $node
     * @deprecated This method should be called from Translation repository.
     * @return array
     */
    public function findUnavailableTranslationIdForNode(Node $node)
    {
        $qb = $this->_em->createQueryBuilder();
        $qb->select('t.id')
            ->from(Translation::class, static::TRANSLATION_ALIAS)
            ->andWhere($qb->expr()->notIn('t.id', ':translationsId'))
            ->setParameter('translationsId', $this->findAvailableTranslationIdForNode($node))
            ->setCacheable(true);

        try {
            $complexArray = $qb->getQuery()->getScalarResult();
            return array_map('current', $complexArray);
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * Create a Criteria object from a search pattern and additional fields.
     *
     * @param string       $pattern  Search pattern
     * @param QueryBuilder $qb       QueryBuilder to pass
     * @param array        $criteria Additional criteria
     * @param string       $alias    SQL query table alias
     *
     * @return QueryBuilder
     */
    protected function createSearchBy(
        $pattern,
        QueryBuilder $qb,
        array &$criteria = [],
        $alias = "obj"
    ) {

        $this->classicLikeComparison($pattern, $qb, $alias);

        /*
         * Search in translations
         */
        $qb->innerJoin($alias . '.nodeSources', static::NODESSOURCES_ALIAS);
        $criteriaFields = [];
        $metadatas = $this->_em->getClassMetadata(NodesSources::class);
        $cols = $metadatas->getColumnNames();
        foreach ($cols as $col) {
            $field = $metadatas->getFieldName($col);
            $type = $metadatas->getTypeOfField($field);
            if (in_array($type, $this->searchableTypes)) {
                $criteriaFields[$field] = '%' . strip_tags(strtolower($pattern)) . '%';
            }
        }
        foreach ($criteriaFields as $key => $value) {
            $fullKey = sprintf('LOWER(%s)', static::NODESSOURCES_ALIAS . '.' . $key);
            $qb->orWhere($qb->expr()->like($fullKey, $qb->expr()->literal($value)));
        }

        /*
         * Handle Tag relational queries
         */
        if (isset($criteria['tags'])) {
            if (is_object($criteria['tags'])) {
                $qb->innerJoin($alias . '.tags', static::TAG_ALIAS, Expr\Join::WITH, $qb->expr()->eq('tg.id', (int) $criteria['tags']->getId()));
            } elseif (is_array($criteria['tags'])) {
                $qb->innerJoin($alias . '.tags', static::TAG_ALIAS, Expr\Join::WITH, $qb->expr()->in('tg.id', $criteria['tags']));
            } elseif (is_integer($criteria['tags'])) {
                $qb->innerJoin($alias . '.tags', static::TAG_ALIAS, Expr\Join::WITH, $qb->expr()->eq('tg.id', (int) $criteria['tags']));
            }
            unset($criteria['tags']);
        }

        $this->prepareComparisons($criteria, $qb, $alias);

        return $qb;
    }

    /**
     *
     * @param  array        $criteria
     * @param  QueryBuilder $qb
     * @param  string       $alias
     *
     * @return QueryBuilder
     */
    protected function prepareComparisons(array &$criteria, QueryBuilder $qb, $alias)
    {
        foreach ($criteria as $key => $value) {
            if ($key == 'translation') {
                if (!$this->hasJoinedNodesSources($qb, $alias)) {
                    $qb->innerJoin($alias . '.nodeSources', static::NODESSOURCES_ALIAS);
                }
                $qb->andWhere($this->buildComparison($value, static::NODESSOURCES_ALIAS . '.', $key, $key, $qb));
            } else {
                $qb->andWhere($this->buildComparison($value, $alias . '.', $key, $key, $qb));
            }
        }

        return $qb;
    }
}
