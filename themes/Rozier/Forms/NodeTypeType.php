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
 * @file NodeTypeType.php
 * @author Ambroise Maupate
 */
namespace Themes\Rozier\Forms;

use Doctrine\Common\Persistence\ObjectManager;
use RZ\Roadiz\CMS\Forms\Constraints\HexadecimalColor;
use RZ\Roadiz\CMS\Forms\Constraints\NonSqlReservedWord;
use RZ\Roadiz\CMS\Forms\Constraints\SimpleLatinString;
use RZ\Roadiz\CMS\Forms\Constraints\UniqueNodeTypeName;
use Symfony\Component\Form\AbstractType;
use RZ\Roadiz\Core\Entities\NodeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 *
 */
class NodeTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        if (empty($options['name'])) {
            $builder->add('name', 'text', [
                'label' => 'name',
                'constraints' => [
                    new NotBlank(),
                    new NonSqlReservedWord(),
                    new SimpleLatinString(),
                    new UniqueNodeTypeName([
                        'entityManager' => $options['em'],
                        'currentValue' => $options['name'],
                    ]),
                ],
            ]);
        }
        $builder->add('displayName', 'text', [
                    'label' => 'nodeType.displayName',
                    'constraints' => [
                        new NotBlank(),
                    ],
                ])
                ->add('description', 'text', [
                    'label' => 'description',
                    'required' => false,
                ])
                ->add('visible', 'checkbox', [
                    'label' => 'visible',
                    'required' => false,
                    'attr' => [
                        'data-desc' => 'this_node_type_will_be_available_for_creating_root_nodes',
                    ]
                ])
                ->add('publishable', 'checkbox', [
                    'label' => 'publishable',
                    'required' => false,
                    'attr' => [
                        'data-desc' => 'enables_published_at_field_for_time_based_publication'
                    ]
                ])
                ->add('reachable', 'checkbox', [
                    'label' => 'reachable',
                    'required' => false,
                    'attr' => [
                        'data-desc' => 'mark_this_typed_nodes_as_reachable_with_an_url'
                    ]
                ])
                ->add('hidingNodes', 'checkbox', [
                    'label' => 'nodeType.hidingNodes',
                    'required' => false,
                    'attr' => [
                        'data-desc' => 'this_node_type_will_hide_all_children_nodes'
                    ]
                ])
                ->add('color', 'text', [
                    'label' => 'nodeType.color',
                    'required' => false,
                    'attr' => ['class' => 'colorpicker-input'],
                    'constraints' => [
                        new HexadecimalColor(),
                    ],
                ])
                ->add('newsletterType', 'checkbox', [
                    'label' => 'nodeType.newsletterType',
                    'required' => false,
                ]);
    }

    public function getName()
    {
        return 'nodetypefield';
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'label' => false,
            'name' => '',
            'data_class' => NodeType::class,
            'attr' => [
                'class' => 'uk-form node-type-form',
            ],
        ]);

        $resolver->setRequired([
            'em',
        ]);
        $resolver->setAllowedTypes('em', ObjectManager::class);
        $resolver->setAllowedTypes('name', 'string');
    }
}
