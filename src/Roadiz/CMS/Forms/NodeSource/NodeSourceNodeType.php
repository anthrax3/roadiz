<?php
/**
 * Copyright (c) 2016. Ambroise Maupate and Julien Blanchet
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
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
 * @file NodeSourceNodeType.php
 * @author Ambroise Maupate <ambroise@rezo-zero.com>
 */
namespace RZ\Roadiz\CMS\Forms\NodeSource;

use Doctrine\ORM\EntityManager;
use RZ\Roadiz\Core\Entities\Node;
use RZ\Roadiz\Core\Entities\NodesSources;
use RZ\Roadiz\Core\Entities\NodeTypeField;
use RZ\Roadiz\Core\Handlers\NodeHandler;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Class NodeSourceNodeType
 * @package RZ\Roadiz\CMS\Forms\NodeSource
 */
class NodeSourceNodeType extends AbstractNodeSourceFieldType
{
    /**
     * @var Node[]
     */
    private $selectedNodes;

    /**
     * @var NodeHandler
     */
    private $nodeHandler;

    /**
     * NodeSourceDocumentType constructor.
     * @param NodesSources $nodeSource
     * @param NodeTypeField $nodeTypeField
     * @param EntityManager $entityManager
     * @param NodeHandler $nodeHandler
     */
    public function __construct(
        NodesSources $nodeSource,
        NodeTypeField $nodeTypeField,
        EntityManager $entityManager,
        NodeHandler $nodeHandler
    ) {
        parent::__construct($nodeSource, $nodeTypeField, $entityManager);

        $this->nodeHandler = $nodeHandler;
        $this->nodeHandler->setNode($this->nodeSource->getNode());
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            array($this, 'onPreSetData')
        )
            ->addEventListener(
                FormEvents::POST_SUBMIT,
                array($this, 'onPostSubmit')
            )
        ;
    }

    /**
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'label' => $this->nodeTypeField->getLabel(),
            'required' => false,
            'mapped' => false,
            'class' => Node::class,
            'multiple' => true,
            'property' => 'id',
            'attr' => [
                'data-nodetypes' => json_encode(explode(',', $this->nodeTypeField->getDefaultValues()))
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'nodes';
    }

    /**
     * @param FormEvent $event
     */
    public function onPreSetData(FormEvent $event)
    {
        $this->selectedNodes = $this->entityManager
            ->getRepository(Node::class)
            ->setDisplayingNotPublishedNodes(true)
            ->findByNodeAndFieldName(
                $this->nodeSource->getNode(),
                $this->nodeTypeField->getName()
            );
        $event->setData($this->selectedNodes);
    }

    /**
     * @param FormEvent $event
     */
    public function onPostSubmit(FormEvent $event)
    {
        $this->nodeHandler->cleanNodesFromField($this->nodeTypeField, false);

        if (is_array($event->getData())) {
            $position = 0;
            foreach ($event->getData() as $nodeId) {
                $tempNode = $this->entityManager
                    ->find(Node::class, (int) $nodeId);
                if ($tempNode !== null) {
                    $this->nodeHandler->addNodeForField($tempNode, $this->nodeTypeField, false, $position);
                    $position++;
                } else {
                    throw new \RuntimeException('Node #'.$nodeId.' was not found during relationship creation.');
                }
            }
        }
    }
}
