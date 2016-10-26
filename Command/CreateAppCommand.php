<?php
/**
 * Created by Benjamin Touchard @ 2016
 * Date: 18/10/16
 */

namespace Kolapsis\Bundle\AndroidGeneratorBundle\Command;

use Doctrine\Bundle\DoctrineBundle\Mapping\DisconnectedMetadataFactory;
use Kolapsis\Bundle\AndroidGeneratorBundle\Generator\EntityGenerator;
use Kolapsis\Bundle\AndroidGeneratorBundle\Generator\FileGenerator;
use Kolapsis\Bundle\AndroidGeneratorBundle\Generator\GradleGenerator;
use Kolapsis\Bundle\AndroidGeneratorBundle\Parser\BundleParser;
use Kolapsis\Bundle\AndroidGeneratorBundle\Twig\TwigFormatterExtension;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

/**
 * AndroidAppCommand
 * Core command to create an Android project based on Symfony Bundle
 */
final class CreateAppCommand extends ContainerAwareCommand {

    static $DEBUG = true;

    private $twig;
    private $output;

    protected function configure()
    {
        $this
            ->setName('android:app:create')
            ->setDescription('Creates Android app.')
            ->setHelp("This command allows you to create an Android App from yor application...")
            ->addArgument('bundle', InputArgument::REQUIRED, 'Target bundle')
            ->addOption('android', 'a', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'App Android version', [24, 21])
            ->addOption('sdk', 's', InputOption::VALUE_REQUIRED, 'Android SDK path', '/opt/android-sdk')
            ->addOption('gradle', 'g', InputOption::VALUE_REQUIRED, 'Gradle version', '2.2.0')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->output = $output;
        $bundleName = $input->getArgument('bundle');

        list($androidVersion, $minSdkVersion) = $input->getOption('android');
        $sdkPath = $input->getOption('sdk');
        $gradleVersion = $input->getOption('gradle');

        $helper = $this->getHelper('question');
        $kernel = $this->getContainer()->get('kernel');
        $bundle = $kernel->getBundle($bundleName);
        if (empty($bundle)) {
            $output->writeln('<error>Error, '.$bundleName.' bundle doesn\'t exists !</error>');
            exit;
        }

        $skeletonDirs = $this->getSkeletonDirs($kernel, $bundle);
        $this->twig = $this->getTwigEnvironment($skeletonDirs);

        $output->writeln([
            '==================================',
            'Android App Generator',
            '==================================',
            '<info>Android API: '.$androidVersion.' (min: '.$minSdkVersion.')</info>',
            '<info>Android SDK path: '.$sdkPath.'</info>',
            '<info>Gradle: '.$gradleVersion.'</info>',
            '',
        ]);

        $appName = str_replace('Bundle', '', $bundle->getName());
        $question = new Question('Please enter the <info>App Name</info> ['.$appName.']: ', $appName);
        $appName = $helper->ask($input, $output, $question);

        if (CreateAppCommand::$DEBUG) $path = '/home/benjamin/Documents/workspace/' . $appName;
        else $path = dirname($kernel->getRootDir()) . '/android';

        $question = new Question('Please enter the <info>App final path</info> ['.$path.']: ', $path);
        $path = $helper->ask($input, $output, $question);

        $question = new Question('Please enter the <info>App domain name</info> [kolapsis.com]: ', 'kolapsis.com');
        $domainName = $helper->ask($input, $output, $question);

        if (CreateAppCommand::$DEBUG) $apiUrl = 'http://192.168.0.28/FullApp/web/api';
        else $apiUrl = 'http://' . preg_replace('/[\s_-]+/', '', strtolower($appName)) . '.' . $domainName;

        $question = new Question('Please enter the <info>App API url</info> ['.$apiUrl.']: ', $apiUrl);
        $apiUrl = $helper->ask($input, $output, $question);

        $tmp = explode('.', $domainName);
        $tmp = array_reverse($tmp);
        $packageName = implode('.', $tmp) . '.' . preg_replace('/[\s_-]+/', '', strtolower($appName));

        $output->writeln('==================================');
        $output->writeln('');
        $output->writeln('Generating App: "' . $appName . '" [package: ' . $packageName . ']');
        $output->writeln('==================================');
        $output->writeln('');

        $parser = new BundleParser($this->output, $this->getContainer());
        $parser->parse($bundle);

        if (!is_dir($path)) {
            $this->output->write('Generate Android application');
            $process = new Process("android create project --target android-$androidVersion --activity MainActivity --package $packageName --gradle --gradle-version $gradleVersion --path $path");
            $process->run();
            $this->output->writeln(' -> <info>OK</info>');

            $fs = new Filesystem();
            $this->output->write('Prepare project structure');
            $fs->mkdir($path . '/app');
            $fs->mkdir($path . '/app/libs');
            $fs->rename($path . '/src', $path . '/app/src');
            $this->output->writeln(' -> <info>OK</info>');
        }

        $entityGenerator = new EntityGenerator($this->twig, $this->output, $packageName, $path);
        $gradleGenerator = new GradleGenerator($this->twig, $this->output, $packageName, $path);
        $fileGenerator = new FileGenerator($this->twig, $this->output, $packageName, $path);

        $gradleGenerator->generate($gradleVersion, $sdkPath, $androidVersion, $minSdkVersion);
        $fileGenerator->generate($appName, $apiUrl, $parser);
        $entityGenerator->generate($parser);

        $this->output->writeln('');
        $this->output->writeln('<comment>Finished !</comment>');
    }

    private function getTwigEnvironment($skeletonDirs) {
        $env = new \Twig_Environment(new \Twig_Loader_Filesystem($skeletonDirs), array(
            'debug' => true,
            'cache' => false,
            'strict_variables' => true,
            'autoescape' => false,
        ));
        $env->addExtension(new TwigFormatterExtension());
        return $env;
    }

    private function getSkeletonDirs(KernelInterface $kernel, BundleInterface $bundle = null) {
        $skeletonDirs = array();

        if (isset($bundle) && is_dir($dir = $bundle->getPath().'/Resources/skeleton')) {
            $skeletonDirs[] = $dir;
            // $this->getSkeletonSubDirs($dir, $skeletonDirs);
        }

        if (is_dir($dir = $kernel->getRootdir().'/Resources/AndroidGeneratorBundle/skeleton')) {
            $skeletonDirs[] = $dir;
            // $this->getSkeletonSubDirs($dir, $skeletonDirs);
        }

        $dir = __DIR__.'/../Resources/skeleton';
        $skeletonDirs[] = $dir;
        $this->getSkeletonSubDirs($dir, $skeletonDirs);
        $skeletonDirs[] = __DIR__ . '/../Resources';

        return $skeletonDirs;
    }

    private function getSkeletonSubDirs($dir, &$skeletonDirs) {
        $finder = new Finder();
        $finder->directories()->in($dir);
        foreach ($finder as $sub) {
            $skeletonDirs[] = $sub->getPathname();
        }
    }
}