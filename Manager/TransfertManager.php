<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\CoreBundle\Manager;

use Claroline\CoreBundle\Library\Transfert\Importer;
use Claroline\CoreBundle\Library\Transfert\ManifestConfiguration;
use Doctrine\Common\Collections\ArrayCollection;
use Claroline\CoreBundle\Library\Transfert\ConfigurationBuilders\GroupsConfigurationBuilder;
use Claroline\CoreBundle\Library\Transfert\ConfigurationBuilders\RolesConfigurationBuilder;
use Claroline\CoreBundle\Library\Transfert\ConfigurationBuilders\ToolsConfigurationBuilder;
use Symfony\Component\Config\Definition\Dumper\YamlReferenceDumper;
use JMS\DiExtraBundle\Annotation as DI;
use Claroline\CoreBundle\Entity\Workspace\Workspace;
use Claroline\CoreBundle\Entity\Resource\Directory;
use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Library\Workspace\Configuration;
use Claroline\CoreBundle\Library\Transfert\RichTextInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * @DI\Service("claroline.manager.transfert_manager")
 */
class TransfertManager
{
    private $listImporters;
    private $rootPath;
    private $om;
    private $container;

    /**
     * @DI\InjectParams({
     *     "om"        = @DI\Inject("claroline.persistence.object_manager"),
     *     "container" = @DI\Inject("service_container")
     * })
     */
    public function __construct($om, $container)
    {
        $this->listImporters = new ArrayCollection();
        $this->om = $om;
        $this->container = $container;
    }

    public function addImporter(Importer $importer)
    {
        return $this->listImporters->add($importer);
    }

    /**
     * Import a workspace
     */
    public function validate(array $data, $validateProperties = true)
    {
        $groupsImporter = $this->getImporterByName('groups');
        $rolesImporter  = $this->getImporterByName('roles');
        $toolsImporter  = $this->getImporterByName('tools');
        $importer = $this->getImporterByName('workspace_properties');
        $usersImporter  = $this->getImporterByName('user');

        //properties
        if ($validateProperties) {
            if (isset($data['properties'])) {
                $properties['properties'] = $data['properties'];
                $importer->validate($properties);
            }
        }

        if (isset($data['members']['users'])) {
            $users['users'] = $data['members']['users'];
            $usersImporter->validate($users);
        }

        if (isset($data['roles'])) {
            $roles['roles'] = $data['roles'];
            $rolesImporter->validate($roles);
        }

        if (isset($data['members']['groups'])) {
            $groups['users'] = $data['members']['groups'];
            $groupsImporter->validate($groups);
        }

        if (isset ($data['tools'])) {
            $tools['tools'] = $data['tools'];
            $toolsImporter->validate($tools);
        }

    }

    public function import(
        Configuration $configuration,
        $isStrict = false
    )
    {
        $data = $configuration->getData();
        $owner = $this->container->get('security.context')->getToken()->getUser();
        $configuration->setOwner($owner);
        $this->setImporters($configuration, $data, $isStrict);
        $this->validate($data);

        //initialize the configuration
        $configuration->setWorkspaceName($data['properties']['name']);
        $configuration->setWorkspaceCode($data['properties']['code']);
        $configuration->setDisplayable($data['properties']['visible']);
        $configuration->setSelfRegistration($data['properties']['self_registration']);
        $configuration->setSelfUnregistration($data['properties']['self_unregistration']);

        $this->createWorkspace($configuration, $owner, true, $isStrict, true);
    }

    /**
     * @param Configuration $configuration
     * @param User $owner
     * @param bool $isValidated
     * @param bool $isStrict
     * @param bool $importUsers
     *
     * @throws InvalidConfigurationException
     * @return SimpleWorkbolspace
     *
     * The template doesn't need to be validated anymore if
     *  - it comes from the self::import() function
     *  - we want to create a user from the default template (it should work no matter what)
     */
    public function createWorkspace(
        Configuration $configuration,
        User $owner,
        $isValidated = false,
        $isStrict = true,
        $importUsers = false
    )
    {
        $configuration->setOwner($owner);
        $data = $configuration->getData();
        $this->om->startFlushSuite();
        $this->setImporters($configuration, $data, $isStrict);

        if (!$isValidated) {
            $this->validate($data, false);
        }

        $workspace = new Workspace();
        $workspace->setName($configuration->getWorkspaceName());
        $workspace->setCode($configuration->getWorkspaceCode());
        $workspace->setDescription($configuration->getWorkspaceDescription());
        $workspace->setGuid($this->container->get('claroline.utilities.misc')->generateGuid());
        $workspace->setDisplayable($configuration->isDisplayable());
        $workspace->setSelfRegistration($configuration->getSelfRegistration());
        $workspace->setSelfUnregistration($configuration->getSelfUnregistration());
        $date = new \Datetime(date('d-m-Y H:i'));
        $workspace->setCreationDate($date->getTimestamp());

        $this->setWorkspaceForImporter($workspace);

        if ($owner) {
            $workspace->setCreator($owner);
        }

        $this->om->persist($workspace);
        $this->om->flush();

        //load roles
        $entityRoles = $this->getImporterByName('roles')->import($data['roles'], $workspace);
        //The manager role is required for every workspace
        $entityRoles['ROLE_WS_MANAGER'] = $this->container->get('claroline.manager.role_manager')->createWorkspaceRole(
            "ROLE_WS_MANAGER_{$workspace->getGuid()}",
            'manager',
            $workspace,
            true
        );

        $owner->addRole($entityRoles['ROLE_WS_MANAGER']);
        $this->om->persist($owner);

        //add base roles to the role array
        $pfRoles = $this->om->getRepository('ClarolineCoreBundle:Role')->findAllPlatformRoles();

        foreach ($pfRoles as $pfRole) {
            $entityRoles[$pfRole->getName()] = $pfRole;
        }

        $entityRoles['ROLE_ANONYMOUS'] = $this->om
            ->getRepository('ClarolineCoreBundle:Role')->findOneByName('ROLE_ANONYMOUS');
        $entityRoles['ROLE_USER'] = $this->om
            ->getRepository('ClarolineCoreBundle:Role')->findOneByName('ROLE_USER');

        if ($importUsers) {
            if (isset($data['members']['users'])) {
                $this->getImporterByName('user')->import($data['members']['users'], $entityRoles);
            }
        }

        $dir = new Directory();
        $dir->setName($workspace->getName());

        $root = $this->container->get('claroline.manager.resource_manager')->create(
            $dir,
            $this->om->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceType')->findOneByName('directory'),
            $owner,
            $workspace,
            null,
            null,
            array()
        );

        //required for roles
        //$this->om->forceFlush();

        $tools = $this->getImporterByName('tools')
            ->import($data['tools'], $workspace, $entityRoles, $root);
        $this->om->endFlushSuite();

        //now we have to parse everything in case there is a rich text
        //rich texts must be located in the tools section

        /*
        foreach ($data['tools'] as $tool) {
            $importer = $this->getImporterByName($tool['tool']['type']);

            if (!$importer) {
                throw new InvalidConfigurationException('The importer ' . $tool['tool']['type'] . ' does not exist');
            }

            if (isset($tool['tool']['data']) && $importer instanceof RichTextInterface) {
                $data['data'] = $tool['tool']['data'];
                $importer->format($data);
            }
        }
        */

        //add missing tools for workspace
        //$this->container->get('claroline.manager.tool_manager')->addMissingWorkspaceTools($workspace);

        return $workspace;
    }

    private function setRootPath($rootPath)
    {
        $this->rootPath = $rootPath;
    }

    private function getImporterByName($name)
    {
        foreach ($this->listImporters as $importer) {
            if ($importer->getName() === $name) {
                return $importer;
            }
        }

        return null;
    }

    public function export(Workspace $workspace)
    {
        foreach ($this->listImporters as $importer) {
            $importer->setListImporters($this->listImporters);
        }

        $data = [];
        $files = [];
        $data['roles'] = $this->getImporterByName('roles')->export($workspace, $files, null);
        $data['tools'] = $this->getImporterByName('tools')->export($workspace, $files, null);

        //generate the archive in a temp dir
        $content = Yaml::dump($data, 10);
        //zip and returns the archive
        $archDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid();
        $archPath = $archDir . DIRECTORY_SEPARATOR . 'archive.zip';
        mkdir($archDir);
        $manifestPath = $archDir . DIRECTORY_SEPARATOR . 'manifest.yml';
        file_put_contents($manifestPath, $content);
        $archive = new \ZipArchive();
        $success = $archive->open($archPath, \ZipArchive::CREATE);

        if ($success === true) {
            $archive->addFile($manifestPath, 'manifest.yml');

            foreach ($files as $uid => $file) {
                $archive->addFile($file, $uid);
            }

            $archive->close();
        } else {
            throw new \Exception('Unable to create archive . ' . $archPath . ' (error ' . $success . ')');
        }

        return $archPath;
    }

    /**
     * Inject the rootPath
     *
     * @param \Claroline\CoreBundle\Library\Workspace\Configuration $configuration
     * @param array $data
     * @param $isStrict
     */
    private function setImporters(Configuration $configuration, array $data, $isStrict)
    {
        foreach ($this->listImporters as $importer) {
            $importer->setRootPath($configuration->getExtractPath());
            $importer->setOwner($configuration->getOwner());
            $importer->setConfiguration($data);
            $importer->setListImporters($this->listImporters);
            $importer->setStrict($isStrict);
        }
    }

    private function setWorkspaceForImporter(Workspace $workspace)
    {
        foreach ($this->listImporters as $importer) {
            $importer->setWorkspace($workspace);
        }
    }

    public function dumpConfiguration()
    {
        $dumper = new YamlReferenceDumper($this->importer);

        $string = '';
        $string .= $dumper->dump($this->getImporterByName('workspace_properties'));
        $string .= $dumper->dump($this->getImporterByName('owner'));
        $string .= $dumper->dump($this->getImporterByName('user'));
        $string .= $dumper->dump($this->getImporterByName('groups'));
        $string .= $dumper->dump($this->getImporterByName('roles'));
        $string .= $dumper->dump($this->getImporterByName('tools'));
        $string .= $dumper->dump($this->getImporterByName('forum'));

        return $string;
    }
}