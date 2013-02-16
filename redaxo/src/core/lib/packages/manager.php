<?php

/**
 * Manager class for packages
 */
abstract class rex_package_manager extends rex_factory_base
{
  /**
   * @var rex_package
   */
  protected $package;

  protected $generatePackageOrder = true;

  private $i18nPrefix;

  private $message;

  /**
   * Constructor
   *
   * @param rex_package $package    Package
   * @param string      $i18nPrefix Prefix for i18n
   */
  protected function __construct(rex_package $package, $i18nPrefix)
  {
    $this->package = $package;
    $this->i18nPrefix = $i18nPrefix;
  }

  /**
   * Creates the manager for the package
   *
   * @param rex_package $package Package
   * @return rex_package_manager
   */
  static public function factory(rex_package $package)
  {
    if (get_called_class() == __CLASS__) {
      $class = $package instanceof rex_plugin ? 'rex_plugin_manager' : 'rex_addon_manager';
      return $class::factory($package);
    }
    $class = static::getFactoryClass();
    return new $class($package);
  }

  /**
   * Returns the message
   *
   * @return string
   */
  public function getMessage()
  {
    return $this->message;
  }

  /**
   * Installs a package
   *
   * @param boolean $installDump When TRUE, the sql dump will be importet
   *
   * @return boolean TRUE on success, FALSE on error
   */
  public function install($installDump = true)
  {
    try {
      // check package directory perms
      $install_dir  = $this->package->getPath();
      if (!rex_dir::isWritable($install_dir)) {
        throw new rex_functional_exception($this->i18n('dir_not_writable', $install_dir));
      }

      // check package.yml
      if (!is_readable($this->package->getPath(rex_package::FILE_PACKAGE))) {
        throw new rex_functional_exception($this->i18n('missing_yml_file'));
      }
      $packageId = $this->package->getProperty('package');
      if ($packageId === null) {
        throw new rex_functional_exception($this->i18n('missing_id', $this->package->getPackageId()));
      }
      if ($packageId != $this->package->getPackageId()) {
        $parts = explode('/', $packageId, 2);
        throw new rex_functional_exception($this->wrongPackageId($parts[0], isset($parts[1]) ? $parts[1] : null));
      }
      if ($this->package->getVersion() === null) {
        throw new rex_functional_exception($this->i18n('missing_version'));
      }

      // check requirements and conflicts
      $message = '';
      if (!$this->checkRequirements()) {
        $message = $this->message;
      }
      if (!$this->checkConflicts()) {
        $message .= $this->message;
      }
      if ($message) {
        throw new rex_functional_exception($message);
      }

      $this->package->setProperty('install', true);

      // include install.php
      if (is_readable($this->package->getPath(rex_package::FILE_INSTALL))) {
        rex_autoload::addDirectory($this->package->getPath('lib'));
        rex_autoload::addDirectory($this->package->getPath('vendor'));

        $this->package->includeFile(rex_package::FILE_INSTALL);
        if (($instmsg = $this->package->getProperty('installmsg', '')) != '') {
          throw new rex_functional_exception($instmsg);
        }
        if (!$this->package->isInstalled()) {
          throw new rex_functional_exception($this->i18n('no_reason'));
        }
      }

      // import install.sql
      $installSql = $this->package->getPath(rex_package::FILE_INSTALL_SQL);
      if ($installDump === true && is_readable($installSql)) {
        rex_sql_util::importDump($installSql);
      }

      $this->saveConfig();

      // copy assets
      $assets = $this->package->getPath('assets');
      if (is_dir($assets)) {
        if (!rex_dir::copy($assets, $this->package->getAssetsPath())) {
          throw new rex_functional_exception($this->i18n('install_cant_copy_files'));
        }
      }

      $this->message = $this->i18n('installed', $this->package->getName());

      return true;

    } catch (rex_functional_exception $e) {
      $this->message = $e->getMessage();
    } catch (rex_sql_exception $e) {
      $this->message = 'SQL error: ' . $e->getMessage();
    }

    $this->package->setProperty('install', false);
    $this->message = $this->i18n('no_install', $this->package->getName()) . '<br />' . $this->message;

    return false;
  }

  /**
   * Uninstalls a package
   *
   * @param boolean $installDump When TRUE, the sql dump will be importet
   *
   * @return boolean TRUE on success, FALSE on error
   */
  public function uninstall($installDump = true)
  {
    $isActivated = $this->package->isActivated();
    if ($isActivated && !$this->deactivate()) {
      return false;
    }

    try {
      $this->package->setProperty('install', false);

      // include uninstall.php
      if (is_readable($this->package->getPath(rex_package::FILE_UNINSTALL))) {
        $this->package->includeFile(rex_package::FILE_UNINSTALL);

        if (($instmsg = $this->package->getProperty('installmsg', '')) != '') {
          throw new rex_functional_exception($instmsg);
        }
        if ($this->package->isInstalled()) {
          throw new rex_functional_exception($this->i18n('no_reason'));
        }
      }

      // import uninstall.sql
      $uninstallSql  = $this->package->getPath(rex_package::FILE_UNINSTALL_SQL);
      if ($installDump === true && is_readable($uninstallSql)) {
        rex_sql_util::importDump($uninstallSql);
      }

      // delete assets
      $assets = $this->package->getAssetsPath();
      if (is_dir($assets) && !rex_dir::delete($assets)) {
        throw new rex_functional_exception($this->i18n('install_cant_delete_files'));
      }

      rex_config::removeNamespace($this->package->getPackageId());

      $this->saveConfig();
      $this->message = $this->i18n('uninstalled', $this->package->getName());

      return true;

    } catch (rex_functional_exception $e) {
      $this->message = $e->getMessage();
    } catch (rex_sql_exception $e) {
      $this->message = 'SQL error: ' . $e->getMessage();
    }

    $this->package->setProperty('install', true);
    if ($isActivated) {
      $this->package->setProperty('status', true);
    }
    $this->saveConfig();
    $this->message = $this->i18n('no_uninstall', $this->package->getName()) . '<br />' . $this->message;

    return false;
  }

  /**
   * Activates a package
   *
   * @return boolean TRUE on success, FALSE on error
   */
  public function activate()
  {
    if ($this->package->isInstalled()) {
      $state = '';
      if (!$this->checkRequirements()) {
        $state .= $this->message;
      }
      if (!$this->checkConflicts()) {
        $state .= $this->message;
      }
      $state = $state ?: true;

      if ($state === true) {
        $this->package->setProperty('status', true);
        if (!rex::isSetup()) {
          if (is_readable($this->package->getPath(rex_package::FILE_BOOT))) {
            rex_autoload::addDirectory($this->package->getPath('lib'));
            rex_autoload::addDirectory($this->package->getPath('vendor'));
            $this->package->includeFile(rex_package::FILE_BOOT);
          }
        }
        $this->saveConfig();
      }
      if ($state === true && $this->generatePackageOrder) {
        self::generatePackageOrder();
      }
    } else {
      $state = $this->i18n('not_installed', $this->package->getName());
    }

    if ($state !== true) {
      // error while config generation, rollback addon status
      $this->package->setProperty('status', false);
      $this->message = $this->i18n('no_activation', $this->package->getName()) . '<br />' . $state;
      return false;
    }

    $this->message = $this->i18n('activated', $this->package->getName());
    return true;
  }

  /**
   * Deactivates a package
   *
   * @return boolean TRUE on success, FALSE on error
   */
  public function deactivate()
  {
    $state = $this->checkDependencies();

    if ($state === true) {
      $this->package->setProperty('status', false);
      $this->saveConfig();
    }

    if ($state === true) {
      // reload autoload cache when addon is deactivated,
      // so the index doesn't contain outdated class definitions
      rex_autoload::removeCache();

      if ($this->generatePackageOrder) {
        self::generatePackageOrder();
      }

      $this->message = $this->i18n('deactivated', $this->package->getName());
      return true;
    }

    $this->message = $this->i18n('no_deactivation', $this->package->getName()) . '<br />' . $this->message;
    return false;
  }

  /**
   * Deletes a package
   *
   * @return boolean TRUE on success, FALSE on error
   */
  public function delete()
  {
    if ($this->package->isSystemPackage()) {
      $this->message = $this->i18n('systempackage_delete_not_allowed');
      return false;
    }
    $state = $this->_delete();
    self::synchronizeWithFileSystem();
    return $state;
  }

  /**
   * Deletes a package
   *
   * @param boolean $ignoreState
   *
   * @return boolean TRUE on success, FALSE on error
   */
  protected function _delete($ignoreState = false)
  {
    // if package is installed, uninstall it first
    if ($this->package->isInstalled() && !$this->uninstall() && !$ignoreState) {
      // message is set by uninstall()
      return false;
    }

    if (!rex_dir::delete($this->package->getPath()) && !$ignoreState) {
      $this->message = $this->i18n('not_deleted', $this->package->getName());
      return false;
    }
    rex_dir::delete($this->package->getDataPath());

    if (!$ignoreState) {
      $this->saveConfig();
      $this->message = $this->i18n('deleted', $this->package->getName());
    }

    return true;
  }

  /**
   * @param string $addonName
   * @param string $pluginName
   * @return string
   */
  abstract protected function wrongPackageId($addonName, $pluginName = null);

  /**
   * Checks whether the requirements are met.
   *
   * @return boolean
   */
  public function checkRequirements()
  {
    if (!$this->checkRedaxoRequirement(rex::getVersion())) {
      return false;
    }

    $state = array();
    $requirements = $this->package->getProperty('requires', array());

    if (isset($requirements['php'])) {
      if (!is_array($requirements['php'])) {
        $requirements['php'] = array('version' => $requirements['php']);
      }
      if (isset($requirements['php']['version']) && !self::matchVersionConstraints(PHP_VERSION, $requirements['php']['version'])) {
        $state[] = $this->i18n('requirement_error_php_version', PHP_VERSION, $requirements['php']['version']);
      }
      if (isset($requirements['php']['extensions']) && $requirements['php']['extensions']) {
        $extensions = (array) $requirements['php']['extensions'];
        foreach ($extensions as $reqExt) {
          if (is_string($reqExt) && !extension_loaded($reqExt)) {
            $state[] = $this->i18n('requirement_error_php_extension', $reqExt);
          }
        }
      }
    }

    if (empty($state)) {
      if (isset($requirements['packages']) && is_array($requirements['packages'])) {
        foreach ($requirements['packages'] as $package => $_) {
          if (!$this->checkPackageRequirement($package)) {
            $state[] = $this->message;
          }
        }
      }
    }

    if (empty($state)) {
      return true;
    }
    $this->message = implode('<br />', $state);
    return false;
  }

  /**
   * Checks whether the redaxo requirement is met.
   *
   * @param string $redaxoVersion REDAXO version
   * @return boolean
   */
  public function checkRedaxoRequirement($redaxoVersion)
  {
    $requirements = $this->package->getProperty('requires', array());
    if (isset($requirements['redaxo']) && !self::matchVersionConstraints($redaxoVersion, $requirements['redaxo'])) {
      $this->message = $this->i18n('requirement_error_redaxo_version', $redaxoVersion, $requirements['redaxo']);
      return false;
    }
    return true;
  }

  /**
   * Checks whether the package requirement is met.
   *
   * @param string $packageId Package ID
   * @return boolean
   */
  public function checkPackageRequirement($packageId)
  {
    $requirements = $this->package->getProperty('requires', array());
    if (!isset($requirements['packages'][$packageId])) {
      return true;
    }
    $package = rex_package::get($packageId);
    if (!$package->isAvailable()) {
      $this->message = $this->i18n('requirement_error_' . $package->getType(), $package->getPackageId());
      return false;
    } elseif (!self::matchVersionConstraints($package->getVersion(), $requirements['packages'][$packageId])) {
      $this->message = $this->i18n('requirement_error_' . $package->getType(), $package->getPackageId(), $package->getVersion(), $requirements['packages'][$packageId]);
      return false;
    }
    return true;
  }

  /**
   * Checks whether the package is in conflict with other packages
   *
   * @return boolean
   */
  public function checkConflicts()
  {
    $state = array();
    $conflicts = $this->package->getProperty('conflicts', array());

    if (isset($conflicts['packages']) && is_array($conflicts['packages'])) {
      foreach ($conflicts['packages'] as $package => $_) {
        if (!$this->checkPackageConflict($package)) {
          $state[] = $this->message;
        }
      }
    }

    if (empty($state)) {
      return true;
    }
    $this->message = implode('<br />', $state);
    return false;
  }

  /**
   * Checks whether the package is in conflict with another package
   *
   * @param string $packageId Package ID
   * @return boolean
   */
  public function checkPackageConflict($packageId)
  {
    $conflicts = $this->package->getProperty('conflicts', array());
    $package = rex_package::get($packageId);
    if (!isset($conflicts['packages'][$packageId]) || !$package->isAvailable()) {
      return true;
    }
    $constraints = $conflicts['packages'][$packageId];
    if (!is_string($constraints) || !$constraints || $constraints === '*') {
      $this->message = $this->i18n('conflict_error_' . $package->getType(), $package->getPackageId());
      return false;
    } elseif (self::matchVersionConstraints($package->getVersion(), $constraints)) {
      $this->message = $this->i18n('conflict_error_' . $package->getType() . '_version', $package->getPackageId(), $constraints);
      return false;
    }
    return true;
  }

  /**
   * Checks if another Package which is activated, depends on the given package
   *
   * @return boolean
   */
  public function checkDependencies()
  {
    $i18nPrefix = 'package_dependencies_error_';
    $state = array();

    foreach (rex_package::getAvailablePackages() as $package) {
      if ($package === $this->package || $package->getAddon() === $this->package)
        continue;

      $requirements = $package->getProperty('requires', array());
      if (isset($requirements['packages'][$this->package->getPackageId()])) {
        $state[] = rex_i18n::msg($i18nPrefix . $package->getType(), $package->getPackageId());
      }
    }

    if (empty($state)) {
      return true;
    }
    $this->message = implode('<br />', $state);
    return false;
  }

  /**
   * Translates the given key
   *
   * @param string $key Key
   *
   * @return string Tranlates text
   */
  protected function i18n($key)
  {
    $args = func_get_args();
    $key = $this->i18nPrefix . $args[0];
    if (!rex_i18n::hasMsg($key)) {
      $key = 'package_' . $args[0];
    }
    $args[0] = $key;

    return call_user_func_array(array('rex_i18n', 'msg'), $args);
  }

  /**
   * Generates the package order
   */
  static protected function generatePackageOrder()
  {
    $early = array();
    $normal = array();
    $late = array();
    $requires = array();
    $add = function ($id) use (&$add, &$normal, &$requires) {
      $normal[] = $id;
      unset($requires[$id]);
      foreach ($requires as $rp => &$ps) {
        unset($ps[$id]);
        if (empty($ps)) {
          $add($rp);
        }
      }
    };
    foreach (rex_package::getAvailablePackages() as $package) {
      $id = $package->getPackageId();
      $load = $package->getProperty('load');
      if ($package instanceof rex_plugin
        && !in_array($load, array('early', 'normal', 'late'))
        && in_array($addonLoad = $package->getAddon()->getProperty('load'), array('early', 'late'))
      ) {
        $load = $addonLoad;
      }
      if ($load === 'early') {
        $early[] = $id;
      } elseif ($load === 'late') {
        $late[] = $id;
      } else {
        $req = $package->getProperty('requires');
        if ($package instanceof rex_plugin) {
          $req['packages'][$package->getAddon()->getPackageId()] = true;
        }
        if (isset($req['packages']) && is_array($req['packages'])) {
          foreach ($req['packages'] as $packageId => $reqP) {
            $package = rex_package::get($packageId);
            if (!in_array($package, $normal) && !in_array($package->getProperty('load'), array('early', 'late'))) {
              $requires[$id][$packageId] = true;
            }
          }
        }
        if (!isset($requires[$id])) {
          $add($id);
        }
      }
    }
    rex::setConfig('package-order', array_merge($early, $normal, array_keys($requires), $late));
  }

  /**
   * Saves the package config
   */
  static protected function saveConfig()
  {
    $config = array();
    foreach (rex_addon::getRegisteredAddons() as $addonName => $addon) {
      $config[$addonName]['install'] = $addon->isInstalled();
      $config[$addonName]['status'] = $addon->isActivated();
      foreach ($addon->getRegisteredPlugins() as $pluginName => $plugin) {
        $config[$addonName]['plugins'][$pluginName]['install'] = $plugin->isInstalled();
        $config[$addonName]['plugins'][$pluginName]['status'] = $plugin->isActivated();
      }
    }
    rex::setConfig('package-config', $config);
  }

  /**
   * Synchronizes the packages with the file system
   */
  static public function synchronizeWithFileSystem()
  {
    $config = rex::getConfig('package-config');
    $addons = self::readPackageFolder(rex_path::src('addons'));
    $registeredAddons = array_keys(rex_addon::getRegisteredAddons());
    foreach (array_diff($registeredAddons, $addons) as $addonName) {
      $manager = rex_addon_manager::factory(rex_addon::get($addonName));
      $manager->_delete(true);
      unset($config[$addonName]);
    }
    foreach ($addons as $addonName) {
      if (!rex_addon::exists($addonName)) {
        $config[$addonName]['install'] = false;
        $config[$addonName]['status'] = false;
        $registeredPlugins = array();
      } else {
        $addon = rex_addon::get($addonName);
        $config[$addonName]['install'] = $addon->isInstalled();
        $config[$addonName]['status'] = $addon->isActivated();
        $registeredPlugins = array_keys($addon->getRegisteredPlugins());
      }
      $plugins = self::readPackageFolder(rex_path::addon($addonName, 'plugins'));
      foreach (array_diff($registeredPlugins, $plugins) as $pluginName) {
        $manager = rex_plugin_manager::factory(rex_plugin::get($addonName, $pluginName));
        $manager->_delete(true);
        unset($config[$addonName]['plugins'][$pluginName]);
      }
      foreach ($plugins as $pluginName) {
        $plugin = rex_plugin::get($addonName, $pluginName);
        $config[$addonName]['plugins'][$pluginName]['install'] = $plugin->isInstalled();
        $config[$addonName]['plugins'][$pluginName]['status'] = $plugin->isActivated();
      }
      if (isset($config[$addonName]['plugins']) && is_array($config[$addonName]['plugins']))
        ksort($config[$addonName]['plugins']);
    }
    ksort($config);

    rex::setConfig('package-config', $config);
    rex_addon::initialize();
  }

  /**
   * Checks the version of the requirement.
   *
   * @param string $version     Version
   * @param string $constraints Constraint list, separated by comma
   * @throws rex_exception
   * @return boolean
   */
  static private function matchVersionConstraints($version, $constraints)
  {
    $rawConstraints = array_filter(array_map('trim', explode(',', $constraints)));
    $constraints = array();
    foreach ($rawConstraints as $constraint) {
      if ($constraint === '*') {
        continue;
      }

      if (!preg_match('/^(?<op>==?|<=?|>=?|!=|~|) ?(?<version>\d+(?:\.\d+)*)(?<wildcard>\.\*)?(?<prerelease>[ -.]?[a-z]+(?:[ -.]?\d+)?)?$/i', $constraint, $match)
        || isset($match['wildcard']) && $match['wildcard'] && ($match['op'] != '' || isset($match['prerelease']) && $match['prerelease'])
      ) {
        throw new rex_exception('Unknown version constraint "' . $constraint . '"!');
      }

      if (isset($match['wildcard']) && $match['wildcard']) {
        $constraints[] = array('>=', $match['version']);
        $pos = strrpos($match['version'], '.') + 1;
        $sub = substr($match['version'], $pos);
        $constraints[] = array('<', substr_replace($match['version'], $sub + 1, $pos));
      } elseif ($match['op'] == '~') {
        $constraints[] = array('>=', $match['version'] . (isset($match['prerelease']) ? $match['prerelease'] : ''));
        if (($pos = strrpos($match['version'], '.')) === false) {
          $constraints[] = array('<', $match['version'] + 1);
        } else {
          $main = '';
          $sub = substr($match['version'], 0, $pos);
          if (($pos = strrpos($sub, '.')) !== false) {
            $main = substr($sub, 0, $pos + 1);
            $sub = substr($sub, $pos + 1);
          }
          $constraints[] = array('<', $main . ($sub + 1));
        }
      } else {
        $constraints[] = array($match['op'] ?: '=', $match['version']);
      }
    }

    foreach ($constraints as $constraint) {
      if (!rex_string::compareVersions($version, $constraint[1], $constraint[0])) {
        return false;
      }
    }

    return true;
  }

  /**
   * Returns the subfolders of the given folder
   *
   * @param string $folder Folder
   * @return string[]
   */
  static private function readPackageFolder($folder)
  {
    $packages = array();

    if (is_dir($folder)) {
      foreach (rex_finder::factory($folder)->dirsOnly() as $file) {
        $packages[] = $file->getBasename();
      }
    }

    return $packages;
  }
}
