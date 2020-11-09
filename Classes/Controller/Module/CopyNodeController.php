<?php
namespace JvMTECH\CopyNode\Controller\Module;

/*
 * This file is part of the JvMTECH.CopyNode package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Doctrine\DBAL;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\NodeServiceInterface;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Error\Messages\Message;
use Neos\Flow\I18n;
use Neos\Flow\Utility\Algorithms;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Neos\Service\UserService;

/**
 * @Flow\Scope("singleton")
 */
class CopyNodeController extends AbstractModuleController
{

    /**
     * @var array
     */
    protected $identifiers = [];

    /**
     * @var array
     * @Flow\InjectConfiguration(package="Neos.Flow", path="persistence.backendOptions")
     */
    protected $backendOptions;

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var NodeServiceInterface
     */
    protected $nodeService;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @Flow\Inject
     * @var I18n\Translator
     */
    protected $translator;

    public function indexAction()
    {
    }

    /**
     * @param string $sourceIdentifier
     * @param string $targetParentIdentifier
     */
    public function copyNodeAction(string $sourceIdentifier, string $targetIdentifier)
    {
        $personalWorkspace = $this->userService->getPersonalWorkspace();

        $dimensions = [];
        $allDimensionPreset = $this->contentDimensionPresetSource->getAllPresets();
        foreach ($allDimensionPreset as $dimensionName => $dimensionConfig) {
            $defaultDimensionPreset = $this->contentDimensionPresetSource->getDefaultPreset($dimensionName);
            $dimensions[$dimensionName] = $defaultDimensionPreset['values'];
        }

        $sourceNodeData = $this->nodeDataRepository->findOneByIdentifier($sourceIdentifier, $personalWorkspace, $dimensions);
        $targetNodeData = $this->nodeDataRepository->findOneByIdentifier($targetIdentifier, $personalWorkspace, $dimensions);

        if ($sourceNodeData === null) {
            $this->addFlashMessage($this->translate('error.sourceNodeNotFound'), 'Error', Message::SEVERITY_ERROR);
        } else if ($targetNodeData === null) {
            $this->addFlashMessage($this->translate('error.targetParentNodeNotFound'), 'Error', Message::SEVERITY_ERROR);
        } else if (!$targetNodeData->getNodeType()->allowsChildNodeType($sourceNodeData->getNodeType())) {
            $this->addFlashMessage($this->translate('error.sourceNodeNotAllowedAsChildNode'), 'Error', Message::SEVERITY_ERROR);
        } else {
            try {
                $newParentPath = $targetNodeData->getPath();
                $newNodeName = $this->nodeService->generateUniqueNodeName($newParentPath);
                $newPath = $newParentPath . '/' . $newNodeName;

                $config = new DBAL\Configuration();
                $connection = DBAL\DriverManager::getConnection($this->backendOptions, $config);
                $statement = "SELECT * FROM neos_contentrepository_domain_model_nodedata WHERE path LIKE '" . $sourceNodeData->getPath() . "%';";
                $result = $connection->query($statement)->fetchAll();

                $nodeData = [];

                foreach ($result as $key => $value) {
                    $path = str_replace($sourceNodeData->getPath(), $newPath, $value['path']);
                    $parentpath = NodePaths::getParentPath($path);

                    $nodeData[$key] = $value;
                    $nodeData[$key]['persistence_object_identifier'] = Algorithms::generateUUID();
                    $nodeData[$key]['workspace'] = $personalWorkspace->getName();
                    $nodeData[$key]['path'] = $path;
                    $nodeData[$key]['pathhash'] = md5($path);
                    $nodeData[$key]['parentpath'] = $parentpath;
                    $nodeData[$key]['parentpathhash'] = md5($parentpath);

                    if (!array_key_exists($value['identifier'], $this->identifiers)) {
                        $this->identifiers[$value['identifier']] = Algorithms::generateUUID();
                    }
                    $nodeData[$key]['identifier'] = $this->identifiers[$value['identifier']];
                }

                foreach ($nodeData as $values) {
                    $connection->insert('neos_contentrepository_domain_model_nodedata', $values);
                }

                $connection->close();
                $this->addFlashMessage($this->translate('message.copied'), 'Success');
            } catch (NodeException $e) {
                $this->addFlashMessage($this->translate('error.copyFailed', [$e->getReferenceCode()]), 'Error', Message::SEVERITY_ERROR);
            }
        }

        $this->redirect('index');
    }

    /**
     * @param string $id
     * @param array $arguments
     * @return string
     * @throws I18n\Exception\IndexOutOfBoundsException
     * @throws I18n\Exception\InvalidFormatPlaceholderException
     */
    protected function translate(string $id, array $arguments = []): string
    {
        return $this->translator->translateById($id, $arguments, null, null, 'Modules', 'JvMTECH.CopyNode');
    }
}
