<?php
namespace JvMTECH\CopyNode\Controller\Module;

use Neos\ContentRepository\Domain\Service\NodeServiceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n;
use Neos\Flow\Utility\Algorithms;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Neos\Service\UserService;
use Doctrine\DBAL;

/**
 * @Flow\Scope("singleton")
 */
class CopyNodeController extends AbstractModuleController
{

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

        $nodeName = $this->nodeService->generateUniqueNodeName($targetNodeData->getPath());
        $newParentPath = $targetNodeData->getPath();
        $newPath = $newParentPath . '/' . $nodeName;

        $config = new DBAL\Configuration();
        $connection = DBAL\DriverManager::getConnection($this->backendOptions, $config);
        $statement = "SELECT * FROM neos_contentrepository_domain_model_nodedata WHERE path LIKE '" . $sourceNodeData->getPath() . "%';";
        $result = $connection->query($statement)->fetchAll();

        $nodeData = [];

        foreach ($result as $key => $value) {
            $path = str_replace($sourceNodeData->getPath(), $newPath, $value['path']);
            $parentpath = str_replace($sourceNodeData->getParentPath(), $newParentPath, $value['parentpath']);

            $nodeData[$key] = $value;
            $nodeData[$key]['persistence_object_identifier'] = Algorithms::generateUUID();
            $nodeData[$key]['identifier'] = Algorithms::generateUUID(); // ToDo: Same identifier for node variants
            $nodeData[$key]['path'] = $path;
            $nodeData[$key]['pathhash'] = md5($path);
            $nodeData[$key]['parentpath'] = $parentpath;
            $nodeData[$key]['parentpathhash'] = md5($parentpath);
        }

        foreach ($nodeData as $values) {
            $connection->insert('neos_contentrepository_domain_model_nodedata', $values);
        }

        $connection->close();

        $this->redirect('index');
    }

    /**
     * @param string $id
     * @param array $arguments
     * @return string
     */


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
