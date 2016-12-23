<?php

namespace Bolt\Extension\cdowdy\betterthumbs\Controller;


use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Bolt\Extension\cdowdy\betterthumbs\Helpers;
use Bolt\Extension\cdowdy\betterthumbs\Helpers\ConfigHelper;


use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


use League\Glide\Urls\UrlBuilderFactory;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;



class BetterThumbsBackendController implements ControllerProviderInterface
{
    protected $app;

    protected $configHelper;

    private $config;


    /**
     * Initiate the controller with Bolt Application instance and extension config.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }


    /**
     * @param Application $app
     * @return ControllerCollection
     */
    public function connect(Application $app)
    {
        /** @var ControllerCollection $ctr */
        $ctr = $app['controllers_factory'];

        $ctr->get('/files', [$this, 'bthumbsFiles'])
            ->bind('betterthumbs_files');

        $ctr->get('/docs', [$this, 'bthumbsDocs'])
            ->bind('betterthumbs_docs');

        $ctr->post('/files/delete', [$this, 'deleteSingle'])
            ->bind('betterthumbs_delete');

        $ctr->post('/files/delete/all', [$this, 'deleteAll'])
            ->bind('betterthumbs_delete_all');

        // make sure the user is logged in and allowed to view the backend pages before rendering
        $ctr->before([$this, 'before']);


        return $ctr;
    }


    /**
     * @param Request $request
     * @param Application $app
     * @return null|RedirectResponse
     */
    public function before(Request $request, Application $app)
    {
        if (!$app['users']->isAllowed('dashboard')) {
            /** @var UrlGeneratorInterface $generator */
            $generator = $app['url_generator'];
            return new RedirectResponse($generator->generate('dashboard'), Response::HTTP_SEE_OTHER);
        }
        return null;
    }


    /**
     * @param Application $app
     * @return mixed
     */
    public function bthumbsDocs(Application $app)
    {
        return $app['twig']->render('betterthumbs.docs.html.twig');
    }


    /**
     * @param Application $app
     * @return mixed
     */
    public function bthumbsFiles(Application $app)
    {
        $configHelper = $configHelper = new ConfigHelper($this->config);
        $signkey = $configHelper->setSignKey();

        $filespath = $app['resources']->getPath('filespath') . '/.cache';
        $allFiles = array_diff(scandir($filespath), array('.', '..'));

        $secureURL = UrlBuilderFactory::create('/', $signkey );

        $cachedImage = [];

        foreach ($allFiles as $key ) {
            $parts = pathinfo($key);
            $cachedImage += [
                $secureURL->getUrl($key, ['w' => 200, 'h' => 133, 'fit' => 'crop' ]) => $parts['basename']
            ];
        }

        $context = [
            'allFiles' => $allFiles,
            'cachedImage' => $cachedImage,
        ];


        return $app['twig']->render('betterthumbs.files.html.twig', $context);
    }


    /**
     * @param Application $app
     * @param Request $request
     * @return mixed
     */
    public function deleteSingle(Application $app, Request $request)
    {
        $betterthumbs = $app['betterthumbs'];

        return $betterthumbs->deleteCache($request->request->get('img'));

    }


    /**
     * @param Application $app
     * @param Request $request
     * @return array
     */
    public function deleteAll(Application $app, Request $request)
    {
        $betterthumbs = $app['betterthumbs'];

        $all = $request->request->get('all') ;
        $removed = [];
        foreach ($all as $key => $image ) {
            $removed = $betterthumbs->deleteCache($image);
        }

        return $removed;
    }
}