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

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Claroline\CoreBundle\Entity\Tool\Tool;
use Claroline\CoreBundle\Entity\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Claroline\CoreBundle\Event\StrictDispatcher;
use Claroline\CoreBundle\Manager\ToolManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use JMS\DiExtraBundle\Annotation as DI;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as EXT;
use Claroline\CoreBundle\Persistence\ObjectManager;

/**
 * @todo if user has ROLE_ANONYMOUS, a 403 should be returned (otherise he'll get a 500)
 */
class DesktopParametersController extends Controller
{
    private $request;
    private $router;
    private $toolManager;
    private $om;

    /**
     * @DI\InjectParams({
     *     "request"      = @DI\Inject("request"),
     *     "urlGenerator" = @DI\Inject("router"),
     *     "toolManager"  = @DI\Inject("claroline.manager.tool_manager"),
     *     "ed"           = @DI\Inject("claroline.event.event_dispatcher"),
     *     "om"           = @DI\Inject("claroline.persistence.object_manager")
     * })
     */
    public function __construct(
        Request $request,
        UrlGeneratorInterface $router,
        ToolManager $toolManager,
        StrictDispatcher $ed,
        ObjectManager $om
    )
    {
        $this->request = $request;
        $this->router = $router;
        $this->toolManager = $toolManager;
        $this->ed = $ed;
        $this->om = $om;
    }

    /**
     * @EXT\Route(
     *     "/tools",
     *     name="claro_tool_properties"
     * )
     *
     * @EXT\Template("ClarolineCoreBundle:Tool\desktop\parameters:toolProperties.html.twig")
     * @EXT\ParamConverter("user", options={"authenticatedUser"=true})
     *
     * Displays the tools configuration page.
     *
     * @param \Claroline\CoreBundle\Entity\User $user
     * @return Response
     */
    public function desktopConfigureToolAction(User $user)
    {
        return array('tools' => $this->toolManager->getDesktopToolsConfigurationArray($user));
    }

    /**
     * @EXT\Route(
     *     "/tools/edit",
     *     name="claro_desktop_tools_roles_edit",
     *     options={"expose"=true}
     * )
     * @EXT\ParamConverter("user", options={"authenticatedUser"=true})
     * @EXT\Method("POST")
     *
     * @param \Claroline\CoreBundle\Entity\User $user
     * @return Response
     */
    public function editToolsRolesAction(User $user)
    {
        $parameters = $this->request->request->all();
        $this->om->startFlushSuite();
        //moving tools;
        foreach ($parameters as $parameter => $value) {
            if (strpos($parameter, 'tool-') === 0) {
                $toolId = (int) str_replace('tool-', '', $parameter);
                $tool = $this->toolManager->getToolById($toolId);
                $this->toolManager->setToolPosition($tool, $value, $user);
            }
        }

        //reset the visiblity for every tool
        $this->toolManager->resetToolsVisiblity($user, null);

        //set tool visibility
        foreach ($parameters as $parameter => $value) {
            if (strpos($parameter, 'chk-') === 0) {
                //regex are evil
                $matches = array();
                preg_match('/tool-(.*)/', $parameter, $matches);
                $tool = $this->toolManager->getToolById((int) $matches[1]);
                $this->toolManager->setDesktopToolVisible($tool, $user);
            }
        }

        $this->om->endFlushSuite();

        return new Response();
    }

    /**
     * @EXT\Route(
     *     "tool/{tool}/config",
     *     name="claro_desktop_tool_config"
     * )
     *
     * @param Tool $tool
     * @return Response
     */
    public function openDesktopToolConfig(Tool $tool)
    {
        $event = $this->ed->dispatch(
            strtolower('configure_desktop_tool_' . $tool->getName()),
            'ConfigureDesktopTool',
            array($tool)
        );

        return new Response($event->getContent());
    }
}
