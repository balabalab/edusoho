<?php

namespace AppBundle\Command;

use Sensio\Bundle\GeneratorBundle\Command\GeneratorCommand;
use Sensio\Bundle\GeneratorBundle\Generator\BundleGenerator;
use Sensio\Bundle\GeneratorBundle\Model\Bundle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

class OldPluginCreateCommand extends GeneratorCommand
{
    protected function configure()
    {
        $this
            ->addArgument(
                'bundlename',
                InputArgument::OPTIONAL,
                '插件名称?'
            )
            ->setName('old-plugin:create')
            ->setDescription('创建插件模板');
    }

    /**
     * @param $name
     *
     * @return Bundle
     */
    protected function createBundleObject($name)
    {
        $bundle = $name.'Bundle';
        $namespace = $name;
        $namespace = $namespace.'\\'.$name.'Bundle';

        $dir = $this->getContainer()->getParameter('kernel.root_dir').'/..';
        $dir = $dir.'/plugins';
        $format = 'yml';

        return new Bundle(
            $namespace,
            $bundle,
            $dir,
            $format,
            $shared = false
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('bundlename');

        if (!$name) {
            throw new \RuntimeException('插件名称不能为空！');
        }

        if (!preg_match('/^[a-zA-Z\s]+$/', $name)) {
            throw new \RuntimeException('插件名称只能为英文！');
        }
        $name = ucfirst($name);
        $generator = $this->getGenerator();

        $bundleObject = $this->createBundleObject($name);
        $generator->generateBundle($bundleObject);

        $output->writeln('创建插件包: <info>OK</info>');

        $errors = array();
        $this->getQuestionHelper()->getRunner($output, $errors);
        $pluginName = $name;
        $dir = $bundleObject->getTargetDirectory().'/..';

        var_dump($pluginName, $dir);
        //write jspn
        $filename = $dir.'/plugin.json';

        $data
            = '{
            "code": "'.$pluginName.'",
            "name": "'.$pluginName.'",
            "description": "",
            "author": "EduSoho官方",
            "version": "1.0.0",
            "support_version": "1.0.0"
        }';

        file_put_contents($filename, $data);

        //mkdir script
        $this->filesystem = new Filesystem();

        $this->filesystem->mkdir($dir.'/Scripts');
        $this->filesystem->mkdir($dir.'/Service');

        $this->filesystem->mkdir($dir.'/Service/'.$pluginName.'');

        $this->filesystem->mkdir($dir.'/Service/'.$pluginName.'/Impl');
        $this->filesystem->mkdir($dir.'/Service/'.$pluginName.'/Dao');
        $this->filesystem->mkdir($dir.'/Service/'.$pluginName.'/Dao/Impl');

        $data = $this->getBaseInstallScript();
        file_put_contents($dir.'/Scripts/BaseInstallScript.php', $data);

        $data = $this->getInstallScript();
        file_put_contents($dir.'/Scripts/InstallScript.php', $data);

        $data = $this->getService($pluginName);
        file_put_contents($dir.'/Service/'.$pluginName.'/'.$pluginName.'Service.php', $data);

        $data = $this->getServiceImpl($pluginName);
        file_put_contents($dir.'/Service/'.$pluginName.'/Impl/'.$pluginName.'ServiceImpl.php', $data);

        $data = $this->getDao($pluginName);
        file_put_contents($dir.'/Service/'.$pluginName.'/Dao/'.$pluginName.'Dao.php', $data);

        $data = $this->getDaoImpl($pluginName);
        file_put_contents($dir.'/Service/'.$pluginName.'/Dao/Impl/'.$pluginName.'DaoImpl.php', $data);
        $this->getQuestionHelper()->writeGeneratorSummary($output, $errors);
    }

    private function getData($data, $pluginName)
    {
        return str_replace('{{name}}', $pluginName, $data);
    }

    protected function createGenerator()
    {
        return new BundleGenerator($this->getContainer()->get('filesystem'));
    }

    public function getDaoImpl($pluginName)
    {
        $data = file_get_contents(__DIR__.'/plugins-tpl/DaoImpl.twig');

        return $this->getData($data, $pluginName);
    }

    public function getDao($pluginName)
    {
        $data = file_get_contents(__DIR__.'/plugins-tpl/Dao.twig');

        return $this->getData($data, $pluginName);
    }

    public function getServiceImpl($pluginName)
    {
        $data = file_get_contents(__DIR__.'/plugins-tpl/ServiceImpl.twig');

        return $this->getData($data, $pluginName);
    }

    public function getService($pluginName)
    {
        $data = file_get_contents(__DIR__.'/plugins-tpl/Service.twig');

        return $this->getData($data, $pluginName);
    }

    private function getInstallScript()
    {
        $data = file_get_contents(__DIR__.'/plugins-tpl/InstallScript.twig');

        return $this->getData($data, '');
    }

    private function getBaseInstallScript()
    {
        $data = file_get_contents(__DIR__.'/plugins-tpl/BaseInstallScript.twig');

        return $this->getData($data, '');
    }
}
