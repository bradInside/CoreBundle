<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\CoreBundle\Controller\Tool;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Claroline\CoreBundle\Persistence\ObjectManager;
use Claroline\CoreBundle\Controller\Tool\AbstractParametersController;
use Claroline\CoreBundle\Entity\Workspace\Workspace;
use Claroline\CoreBundle\Entity\Tool\Tool;
use Claroline\CoreBundle\Entity\Tool\OrderedTool;
use Claroline\CoreBundle\Manager\ToolManager;
use Claroline\CoreBundle\Manager\RoleManager;
use Claroline\CoreBundle\Manager\RightsManager;
use Claroline\CoreBundle\Manager\ResourceManager;
use Claroline\CoreBundle\Form\Factory\FormFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as EXT;
use JMS\DiExtraBundle\Annotation as DI;

class WorkspaceToolsParametersController extends AbstractParametersController
{
    private $toolManager;
    private $roleManager;
    private $rightsManager;
    private $resourceManager;
    private $formFactory;
    private $request;
    private $om;

    /**
     * @DI\InjectParams({
     *     "toolManager"     = @DI\Inject("claroline.manager.tool_manager"),
     *     "roleManager"     = @DI\Inject("claroline.manager.role_manager"),
     *     "rightsManager"   = @DI\Inject("claroline.manager.rights_manager"),
     *     "resourceManager" = @DI\Inject("claroline.manager.resource_manager"),
     *     "formFactory"     = @DI\Inject("claroline.form.factory"),
     *     "request"         = @DI\Inject("request"),
     *     "om"              = @DI\Inject("claroline.persistence.object_manager")
     * })
     */
    public function __construct(
        ToolManager $toolManager,
        RoleManager $roleManager,
        RightsManager $rightsManager,
        ResourceManager $resourceManager,
        FormFactory $formFactory,
        Request $request,
        ObjectManager $om
    )
    {
        $this->toolManager     = $toolManager;
        $this->roleManager     = $roleManager;
        $this->rightsManager   = $rightsManager;
        $this->resourceManager = $resourceManager;
        $this->formFactory     = $formFactory;
        $this->request         = $request;
        $this->om              = $om;
    }

    /**
     * @EXT\Route(
     *     "/{workspace}/tools",
     *     name="claro_workspace_tools_roles"
     * )
     * @EXT\Template("ClarolineCoreBundle:Tool\workspace\parameters:toolRoles.html.twig")
     *
     * @param Workspace $workspace
     * @return array
     */
    public function workspaceToolsRolesAction(Workspace $workspace)
    {
        $this->checkAccess($workspace);

        return array(
            'roles' => $this->roleManager->getWorkspaceConfigurableRoles($workspace),
            'workspace' => $workspace,
            'toolPermissions' => $this->toolManager->getWorkspaceToolsConfigurationArray($workspace)
        );
    }

    /**
     * @EXT\Route(
     *     "/{workspace}/tools/edit",
     *     name="claro_workspace_tools_roles_edit",
     *     options={"expose"=true}
     * )
     * @EXT\Method("POST")
     */
    public function editToolsRolesAction(Workspace $workspace)
    {
        $this->checkAccess($workspace);
        $parameters = $this->request->request->all();
        $this->om->startFlushSuite();
        //moving tools;
        foreach ($parameters as $parameter => $value) {
            if (strpos($parameter, 'tool-') === 0) {
                $toolId = (int) str_replace('tool-', '', $parameter);
                $tool = $this->toolManager->getToolById($toolId);
                $this->toolManager->setToolPosition($tool, $value, null, $workspace);
            }
        }

        //reset the visiblity for every tool
        $this->toolManager->resetToolsVisiblity(null, $workspace);

        //set tool visibility
        foreach ($parameters as $parameter => $value) {
            if (strpos($parameter, 'chk-') === 0) {
                //regex are evil
                $matches = array();
                preg_match('/tool-(.*?)-/', $parameter, $matches);
                $tool = $this->toolManager->getToolById((int) $matches[1]);
                preg_match('/role-(.*)/', $parameter, $matches);
                $role = $this->roleManager->getRole($matches[1]);
                $this->toolManager->addRole($tool, $role, $workspace);
            }
        }

        $this->om->endFlushSuite();

        return new Response();
    }

    /**
     * @EXT\Route(
     *     "/{workspace}/tools/{tool}/editform",
     *     name="claro_workspace_order_tool_edit_form"
     * )
     *
     * @EXT\Template("ClarolineCoreBundle:Tool\workspace\parameters:toolNameModalForm.html.twig")
     *
     * @param Workspace $workspace
     * @param Tool              $tool
     *
     * @return Response
     */
    public function workspaceOrderToolEditFormAction(Workspace $workspace, Tool $tool)
    {
        $this->checkAccess($workspace);
        $ot = $this->toolManager->getOneByWorkspaceAndTool($workspace, $tool);

        return array(
            'form' => $this->formFactory->create(FormFactory::TYPE_ORDERED_TOOL, array(), $ot)->createView(),
            'workspace' => $workspace,
            'wot' => $ot
        );
    }

    /**
     * @EXT\Route(
     *     "/{workspace}/tools/{workspaceOrderTool}/edit",
     *     name="claro_workspace_order_tool_edit"
     * )
     * @EXT\Method("POST")
     *
     * @EXT\Template("ClarolineCoreBundle:Tool\workspace\parameters:workspaceOrderToolEdit.html.twig")
     *
     * @param Workspace $workspace
     * @param OrderedTool $ot
     *
     * @return Response
     */
    public function workspaceOrderToolEditAction(Workspace $workspace, OrderedTool $workspaceOrderTool)
    {
        $this->checkAccess($workspace);
        $form = $this->formFactory->create(FormFactory::TYPE_ORDERED_TOOL, array(), $workspaceOrderTool);
        $form->handleRequest($this->request);

        if ($form->isValid()) {
            $this->toolManager->editOrderedTool($form->getData());

            return new JsonResponse(
                array(
                    'tool_id' => $workspaceOrderTool->getTool()->getId(),
                    'ordered_tool_id' => $workspaceOrderTool->getId(),
                    'name' => $workspaceOrderTool->getName()
                )
            );
        }

        return array(
            'form' => $form->createView(),
            'workspace' => $workspace,
            'wot' => $workspaceOrderTool
        );
    }

    /**
     * @EXT\Route(
     *     "/{workspace}/tools/order/update/tool/{orderedTool}/with/{otherOrderedTool}/mode/{mode}",
     *     name="claro_workspace_update_ordered_tool_order",
     *     options={"expose"=true}
     * )
     * @param Workspace $workspace
     * @param OrderedTool $orderedTool
     * @param OrderedTool $otherOrderedTool
     * @param string $mode
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function updateWorkspaceOrderedToolOrderAction(
        Workspace $workspace,
        OrderedTool $orderedTool,
        OrderedTool $otherOrderedTool,
        $mode
    )
    {
        $this->checkAccess($workspace);

        if ($orderedTool->getWorkspace() === $workspace &&
            $otherOrderedTool->getWorkspace() === $workspace) {

            $order = $orderedTool->getOrder();
            $otherOrder = $otherOrderedTool->getOrder();

            if ($mode === 'previous') {

                if ($otherOrder > $order) {
                    $newOrder = $otherOrder;
                } else {
                    $newOrder = $otherOrder + 1;
                }
            } elseif ($mode === 'next') {

                if ($otherOrder > $order) {
                    $newOrder = $otherOrder - 1;
                } else {
                    $newOrder = $otherOrder;
                }
            } else {

                return new Response('Bad Request', 400);
            }

            $this->toolManager->updateOrderedToolOrder(
                $orderedTool,
                $newOrder
            );

            return new Response('success', 204);
        } else {

            throw new AccessDeniedException();
        }
    }
}
