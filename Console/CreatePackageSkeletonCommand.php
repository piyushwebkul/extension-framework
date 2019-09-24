<?php

namespace Webkul\UVDesk\ExtensionFrameworkBundle\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

use Webkul\UVDesk\ExtensionFrameworkBundle\Definition\PackageMetadata;
use Webkul\UVDesk\ExtensionFrameworkBundle\Definition\PackageInterface;
use Webkul\UVDesk\ExtensionFrameworkBundle\Definition\ConfigurablePackageInterface;



class CreatePackageSkeletonCommand extends Command
{
  use LockableTrait;
  
  protected $container;
  protected $kernel;
  protected static $defaultName = 'uvdesk_extensions:create-package-skeleton';
  
  public function __construct(ContainerInterface $container, KernelInterface $kernel)
  { 
    $this->container = $container;
    $this->kernel = $kernel;
    parent::__construct();
  }
  
  protected function execute(InputInterface $input, OutputInterface $output)
  { 
    $io = new SymfonyStyle($input, $output);
    $io->title('Welcome to UVDesk new Package Skeleton Wizard');
    
    if ('dev' != $this->container->get('kernel')->getEnvironment()) {
      $io->error('This command is only allowed to be used in development environment.');
      return 0;
    }
    if (!$this->lock()) {
      $io->error('The command is already running in another process.');
      return 0;
    }
    $rootPath = $this->container->getParameter('uvdesk_extensions.dir');
    
    // Vendor
    $vendor = $io->ask('Name your vendor (PascalCase)?', 'UVDesk', function ($value) { 
      if (empty($value)) {
        throw new \RuntimeException('This Field cannot be blank');
      }
      return $value;
     });
    $vendorKebabCase = $this->pascalCaseToKebabCase($vendor);
    $vendorDirectory = "$rootPath/$vendorKebabCase"; 
    $this->createDirectory($vendorDirectory, false);
    
    try {
      // Package
      $package = $io->ask('Name your package (PascalCase)?', "MyPackage", function ($value) { 
        if (empty($value)) {
          throw new \RuntimeException('This Field cannot be blank');
        }
        return preg_replace("/Package/", "", $value);
      });
      $packageKebabCase = $this->pascalCaseToKebabCase($package);
      $packageFullyQualifiedDir = "$rootPath/$vendorKebabCase/$packageKebabCase"; 
      
      // Create Directory Structure
      $this->createDirectory($packageFullyQualifiedDir);
      $this->createPackageSupportDirectories($packageFullyQualifiedDir);
      
      $packageKCUnderscore = preg_replace("/-/", "_", $packageKebabCase);
      $vendorKCUnderscore = preg_replace("/-/", "_", $vendorKebabCase);
      
      
      //Creating Package services.yaml file
      if (!$this->createPackageServiceYamlFile([
        'packageDirectory' => $packageFullyQualifiedDir,
        'packagePC' => $package,
        'vendorPC' => $vendor
        ])) {
          throw new \RuntimeException('Unable to create services.yaml file');
        }
        
        // Writing Package extension.json file
        $this->createPackageExtensionFile([
        'packagePC' => $package,
        'packageKC' => $packageKebabCase,
        'vendorPC' => $vendor,
        'vendorKC' => $vendorKebabCase,
        'packageDirectory' => $packageFullyQualifiedDir,
        'packageKCUnderscore' => $packageKCUnderscore,
        'vendorKCUnderscore' => $vendorKCUnderscore,
      ], $io);
      
      // Writing Package Class Files
      $this->createPackagePackageClassFile([
        'packagePC' => $package,
        'packageKC' => $packageKebabCase,
        'vendorPC' => $vendor,
        'vendorKC' => $vendorKebabCase,
        'packageDirectory' => $packageFullyQualifiedDir,
        'packageKCUnderscore' => $packageKCUnderscore,
        'vendorKCUnderscore' => $vendorKCUnderscore,
      ], $io->confirm('Do you want you package to be Configurable?'));
      
      $io->success('Package Skeleton Successfully Created.');
      
      if ($io->confirm('Do you want you configure your package?')) {
        $io->write($this->configurePackage());
      }
    } catch(\Exception $e) {
      if (is_dir($vendorDirectory)) {
        $this->removeDirectory($vendorDirectory);
      }
      dump($e->getMessage());
      die;
    }
    
    $this->release();
  }
  
  private function createDirectory($directory, $alreadyExistThrowException = true) {
    if (is_dir($directory)) {
      if ($alreadyExistThrowException) {
        throw new \RuntimeException("directory $directory already exist.");
      } else {
        return true;
      }
    } else {
      if (!mkdir($directory, 0755, true)) {
        throw new \RuntimeException("Unable to create $directory directory.");
      }
      return true;
    }
  }
  
  private function createPackageSupportDirectories($packageFullyQualifiedDir) {
    $this->createDirectory("$packageFullyQualifiedDir/src");
    $this->createDirectory("$packageFullyQualifiedDir/templates");
    $this->createDirectory("$packageFullyQualifiedDir/src/Resources/config");
  }
  
  
  private function createPackageExtensionFile($packageFileMetadata = [], $io) {
    
    $packageContentArray['name'] = "$packageFileMetadata[vendorKC]/$packageFileMetadata[packageKC]";
    $packageContentArray['description'] = $io->ask('Enter a short description of your package?');
    
    $packageType = $io->choice('Select the type of your package', ['uvdesk-module'], 'uvdesk-module');
    switch($packageType) {
      case 0:
      $packageContentArray['type'] = 'uvdesk-module';
        break;
      }
    
    // Authors
    while (true) {
      $io->write('Add a new Author (Press enter to skip)');
      $author['name'] = $io->ask('Enter the author\' name of your package (John Doe)?');
      if (empty($author['name'])) {
        break;
      }
      $author['email'] = $io->ask('Enter the author email\' of your package (johndoe@uvdesk.com)?');
      $packageContentArray['authors'][] = $author;
    }

    $packageContentArray['autoload'] = [
      "UVDesk\\CommunityPackages\\$packageFileMetadata[vendorPC]\\$packageFileMetadata[packagePC]\\" => "src/"
    ];
    
    $packageContentArray['package'] = [
      "UVDesk\\CommunityPackages\\$packageFileMetadata[vendorPC]\\$packageFileMetadata[packagePC]\\$packageFileMetadata[packagePC]"."Package" => ["all"]
    ];  
    return false !== file_put_contents("{$packageFileMetadata['packageDirectory']}/extension.json", json_encode($packageContentArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  private function createPackagePackageClassFile(array $packageMetadata, bool $isConfigurablePackage = true): bool
  {
    if ($isConfigurablePackage) {
      file_put_contents("$packageMetadata[packageDirectory]/templates/default-template.yaml", "$packageMetadata[vendorKCUnderscore]_$packageMetadata[packageKCUnderscore]: ~");
      $packageConfigurationTemplate = preg_replace(
        [
          "/\[VendorName\]/",
          "/\[PackageName\]/",
          "/\[VendorKC\]/",
          "/\[PackageKC\]/"
        ], 
        [
          $packageMetadata['vendorPC'],
          $packageMetadata['packagePC'],
          $packageMetadata['vendorKCUnderscore'],
          $packageMetadata['packageKCUnderscore']
        ], $this->getPackageConfigurationTemplate()); 
      $this->createDirectory("$packageMetadata[packageDirectory]/src/DependencyInjection");     
      if (!file_put_contents("$packageMetadata[packageDirectory]/src/DependencyInjection/PackageConfiguration.php", $packageConfigurationTemplate)) {
        return false;
      }
    }

    $packageTemplate = $isConfigurablePackage ? $this->getConfigurablePackageTemplate() : $this->getPackageTemplate();
    $packageTemplate = preg_replace(["/\[PackageName\]/", "/\[VendorName\]/"], [ $packageMetadata['packagePC'], $packageMetadata['vendorPC'] ], $packageTemplate);
    
    if ($packageTemplate !== null) {
      return false !== file_put_contents("$packageMetadata[packageDirectory]/src/$packageMetadata[packagePC]"."Package.php", $packageTemplate);
    }

    return false;
  }


  private function createPackageServiceYamlFile(array $packageMetadata): bool {
    return false !== file_put_contents("{$packageMetadata['packageDirectory']}/src/Resources/config/services.yaml",
      preg_replace(["/\[VendorName\]/", "/\[PackageName\]/"], 
      [$packageMetadata['vendorPC'], $packageMetadata['packagePC']],
      $this->getPackageServiceYamlTemplate()));
  }


  private function getConfigurablePackageTemplate() {
    return <<< CPT
<?php
namespace UVDesk\CommunityPackages\[VendorName]\[PackageName];

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Webkul\UVDesk\ExtensionFrameworkBundle\Definition\Package\ConfigurablePackage;
use Webkul\UVDesk\ExtensionFrameworkBundle\Definition\Package\ConfigurablePackageInterface;

class [PackageName]Package extends ConfigurablePackage implements ConfigurablePackageInterface
{
    public function getConfiguration() : ConfigurationInterface
    {
        return new DependencyInjection\PackageConfiguration();
    }

    public function install() : void
    {
        \$template = file_get_contents(__DIR__ . "/../templates/default-template.yaml");

        \$this->updatePackageConfiguration(\$template);
    }
}
CPT;
  }

  private function getPackageTemplate() {  
    return <<< PT
<?php
namespace UVDesk\CommunityPackages\[VendorName]\[PackageName];

use Webkul\UVDesk\ExtensionFrameworkBundle\Definition\Package\Package;
use Webkul\UVDesk\ExtensionFrameworkBundle\Definition\Package\PackageInterface;

class [PackageName]Package extends Package implements PackageInterface
{
    // Do Something
}
PT;
  }

  private function getPackageConfigurationTemplate() {
    return <<< PCT
<?php
namespace UVDesk\CommunityPackages\[VendorName]\[PackageName]\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class PackageConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        \$treeBuilder = new TreeBuilder('[VendorKC]_[PackageKC]');

        return \$treeBuilder;
    }
}
PCT;
  }

  private function getPackageServiceYamlTemplate() {
    return <<< PSY
services:
  _defaults:
      autowire: true
      autoconfigure: true
      public: false
  
  UVDesk\CommunityPackages\[VendorName]\[PackageName]\:
    resource: '../../*'
PSY;
  }

  private function pascalCaseToKebabCase($pascalCase) {
    preg_match_all("/[A-Z]+[a-z]*/", $pascalCase, $matches);

    return implode('-', array_map( function($elem){ return strtolower($elem); }, $matches[0]));
  }

  private function configurePackage(): string {
    $application = new Application($this->kernel);
    $application->setAutoExit(false);
    $input = new ArrayInput([
      'command' => 'uvdesk_extensions:configure-extensions'
    ]);
    $output = new BufferedOutput();
    $application->run($input, $output);
    return $output->fetch();
  }

  private function removeDirectory($parentDir) {
    if (is_dir($parentDir)) {
      $dirs = array_diff(scandir($parentDir), ['.', '..']);
      if (!empty($dirs)) {
        foreach($dirs as $d) {
          $this->filterDirectory("$parentDir/$d");
          rmdir("$parentDir/$d"); 
        }
      }
      rmdir($parentDir);
    }
  }

  private function filterDirectory($parentDir) {
    $dirs = array_diff(scandir($parentDir), ['.', '..']);
    foreach($dirs as $d) {
      if (is_dir("$parentDir/$d")){
        $this->filterDirectory("$parentDir/$d");
        rmdir("$parentDir/$d");
      } else {
        unlink("$parentDir/$d");
      }
    } 
  }

}