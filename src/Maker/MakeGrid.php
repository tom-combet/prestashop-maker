<?php
/**
 * Copyright since 2019 Kaudaj.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@kaudaj.com so we can send you a copy immediately.
 *
 * @author    Kaudaj <info@kaudaj.com>
 * @copyright Since 2019 Kaudaj
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

declare(strict_types=1);

namespace Kaudaj\PrestaShopMaker\Maker;

use Kaudaj\PrestaShopMaker\Builder\Grid\ControllerBuilder;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\Doctrine\DoctrineHelper;
use Symfony\Bundle\MakerBundle\FileManager;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Bundle\MakerBundle\Util\ClassSourceManipulator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\Question;

final class MakeGrid extends EntityBasedMaker
{
    /**
     * @var string
     */
    private $gridNamespace;

    public function __construct(FileManager $fileManager, ?string $destinationModule, DoctrineHelper $entityHelper)
    {
        parent::__construct($fileManager, $destinationModule, $entityHelper);

        $this->templatesPath .= 'grid/';
    }

    public static function getCommandName(): string
    {
        return 'make:prestashop:grid';
    }

    /**
     * @return string[]
     */
    public static function getCommandAliases(): array
    {
        return ['make:ps:grid'];
    }

    public static function getCommandDescription(): string
    {
        return 'Creates a Grid';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConf): void
    {
        parent::configureCommand($command, $inputConf);

        $helpFileContents = file_get_contents($this->rootPath.'src/Resources/help/MakeGrid.txt');
        if ($helpFileContents) {
            $command->setHelp($helpFileContents);
        }
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        parent::generate($input, $io, $generator);

        $this->gridNamespace = (!$this->destinationModule ? 'Core\\' : '').'Grid\\';

        if (!$this->destinationModule) {
            $question = new Question('Translation domain for the grid', 'Admin.Translation.Domain');
            $translationDomain = $io->askQuestion($question);
        } else {
            $translationDomain = 'Modules.'.ucfirst(strtolower($this->destinationModule)).'.Admin';
        }

        $this->generateGridDefinitionFactory($translationDomain);
        $this->generateFilters();
        $this->generateQueryBuilder();
        $this->generateGridDataFactory();
        $this->generateGridFactory();

        $this->generateController();

        $this->generateTemplates();

        $generator->writeChanges();

        $this->writeSuccessMessage($io);
    }

    private function generateGridDefinitionFactory(string $translationDomain): void
    {
        $namespace = "{$this->gridNamespace}Definition\\Factory\\";

        $classNameDetails = $this->generator->createClassNameDetails(
            "{$this->entityClassName}",
            $namespace,
            'GridDefinitionFactory'
        );

        $entityPlural = Str::singularCamelCaseToPluralCamelCase(Str::asCamelCase($this->entityClassName));
        $gridId = Str::asSnakeCase($entityPlural);
        $gridName = ucfirst(strtolower(Str::asHumanWords($entityPlural)));

        $gridColumns = [];
        foreach ($this->getEntityProperties() as $property) {
            $field = Str::asSnakeCase($property->getName());
            $title = Str::asHumanWords($property->getName());

            $gridColumns[$field] = $title;
        }

        $this->generateClass(
            $classNameDetails->getFullName(),
            'DefinitionFactory.tpl.php',
            [
                'grid_id' => $gridId,
                'grid_name' => $gridName,
                'grid_columns' => $gridColumns,
                'translation_domain' => $translationDomain,
            ]
        );

        $entitySnakeCase = Str::asSnakeCase($this->entityClassName);
        $serviceName = $this->servicesPrefix.$this->formatNamespaceForService($namespace).$entitySnakeCase;

        $this->addService(
            $serviceName,
            [
                'public' => true,
                'class' => $classNameDetails->getFullName(),
                'parent' => 'prestashop.core.grid.definition.factory.abstract_grid_definition',
            ]
        );
    }

    private function generateFilters(): void
    {
        $classNameDetails = $this->generator->createClassNameDetails(
            "{$this->entityClassName}",
            (!$this->destinationModule ? 'Core\\' : '').'Search\\Filters\\',
            'Filters'
        );

        $this->generateClass(
            $classNameDetails->getFullName(),
            'Filters.tpl.php'
        );
    }

    private function generateQueryBuilder(): void
    {
        $namespace = "{$this->gridNamespace}Query\\";

        $classNameDetails = $this->generator->createClassNameDetails(
            "{$this->entityClassName}",
            $namespace,
            'QueryBuilder'
        );

        $entitySnakeCase = Str::asSnakeCase($this->entityClassName);
        $serviceName = $this->servicesPrefix.$this->formatNamespaceForService($namespace).$entitySnakeCase;

        $tableAlias = implode(
            array_map(
                function ($word) { return substr($word, 0, 1); },
                explode('_', $entitySnakeCase)
            )
        );

        $selectStatement = "$tableAlias.id_$entitySnakeCase";
        foreach ($this->getEntityProperties() as $property) {
            $field = Str::asSnakeCase($property->getName());

            $selectStatement .= ", $tableAlias.$field";
        }

        $this->generateClass(
            $classNameDetails->getFullName(),
            'QueryBuilder.tpl.php',
            [
                'table_alias' => $tableAlias,
                'select_statement' => $selectStatement,
            ]
        );

        $serviceName = $this->servicesPrefix.$this->formatNamespaceForService($namespace).$entitySnakeCase;

        $this->addService(
            $serviceName,
            [
                'public' => true,
                'class' => $classNameDetails->getFullName(),
                'parent' => 'prestashop.core.grid.abstract_query_builder',
                'arguments' => [
                    '@=service("prestashop.adapter.legacy.context").getContext().language.id',
                    '@=service("prestashop.adapter.legacy.context").getContext().shop.id',
                ],
            ]
        );
    }

    private function generateGridDataFactory(): void
    {
        $entitySnakeCase = Str::asSnakeCase($this->entityClassName);
        $entityPlural = Str::singularCamelCaseToPluralCamelCase(Str::asCamelCase($this->entityClassName));
        $gridId = Str::asSnakeCase($entityPlural);
        $serviceName = $this->servicesPrefix.".grid.data.factory.{$entitySnakeCase}_data_factory";

        $this->addService(
            $serviceName,
            [
                'class' => 'PrestaShop\PrestaShop\Core\Grid\Data\Factory\DoctrineGridDataFactory',
                'arguments' => [
                    '@'.$this->servicesPrefix.".grid.query.{$entitySnakeCase}_query_builder",
                    '@prestashop.core.hook.dispatcher',
                    '@prestashop.core.grid.query.doctrine_query_parser',
                    $gridId,
                ],
            ]
        );
    }

    private function generateGridFactory(): void
    {
        $entitySnakeCase = Str::asSnakeCase($this->entityClassName);
        $serviceName = $this->servicesPrefix.$this->formatNamespaceForService($this->gridNamespace.'Factory\\').$entitySnakeCase;

        $definitionFactoryService = $this->servicesPrefix
            .$this->formatNamespaceForService($this->gridNamespace.'Grid\\Definition\\Factory\\').$entitySnakeCase;
        $dataFactoryService = $this->servicesPrefix
            .$this->formatNamespaceForService($this->gridNamespace.'Grid\\Data\\Factory\\').$entitySnakeCase;

        $this->addService(
            $serviceName,
            [
                'class' => 'PrestaShop\PrestaShop\Core\Grid\GridFactory',
                'arguments' => [
                    "@$definitionFactoryService",
                    "@$dataFactoryService",
                    '@prestashop.core.grid.filter.form_factory',
                    '@prestashop.core.hook.dispatcher',
                ],
            ]
        );
    }

    private function generateController(): void
    {
        $controllerClassNameDetails = $this->generator->createClassNameDetails(
            $this->entityClassName,
            (!$this->destinationModule ? 'PrestaShopBundle\\' : '').'Controller\\Admin\\',
            'Controller'
        );

        $controllerSourceCode = '';
        $controllerPath = '';

        if (!class_exists($controllerClassNameDetails->getFullName())) {
            $controllerPath = $this->generator->generateController(
                $controllerClassNameDetails->getFullName(),
                $this->templatesPath.'Controller.tpl.php',
                $this->getDefaultVariablesForGeneration()
            );

            $controllerSourceCode = $this->generator->getFileContentsForPendingOperation($controllerPath);
        } else {
            $controllerPath = $this->fileManager->getRelativePathForFutureClass($controllerClassNameDetails->getFullName());
            if ($controllerPath) {
                $controllerSourceCode = $this->fileManager->getFileContents($controllerPath);
            }
        }

        if (!$controllerPath || !$controllerSourceCode) {
            return;
        }

        $manipulator = new ClassSourceManipulator($controllerSourceCode, true);

        $controllerBuilder = new ControllerBuilder($this->entityClassName);

        if (!method_exists($controllerClassNameDetails->getFullName(), 'indexAction')) {
            $controllerBuilder->addIndexAction($manipulator);
        }

        $this->generator->dumpFile($controllerPath, $manipulator->getSourceCode());
    }

    private function generateTemplates(): void
    {
        $templatesPath = (!$this->destinationModule ? 'src/PrestaShopBundle/Resources/' : '')
            ."views/Admin/{$this->entityClassName}/"
        ;

        $this->generateFile(
            "{$templatesPath}index.html.twig",
            'index.tpl.php'
        );
    }

    protected function getDefaultVariablesForGeneration(): array
    {
        return parent::getDefaultVariablesForGeneration() + [
            'grid_namespace' => $this->gridNamespace,
        ];
    }
}
