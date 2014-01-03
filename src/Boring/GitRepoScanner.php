<?php
namespace Boring;
use Symfony\Component\Finder\Finder;

class GitRepoScanner {

  /**
   * @param string $basedir
   * @return array of GitRepo
   */
  public function scan($basedir) {
    $gitRepos = array();
    $finder = new Finder();
    $finder->in($basedir)
      ->ignoreUnreadableDirs()
      ->ignoreVCS(FALSE)
      ->ignoreDotFiles(FALSE)
      ->directories()
      ->name('.git');
    foreach ($finder as $file) {
      $path = dirname($file);
      $gitRepos[] = new GitRepo($path);
    }
    return $gitRepos;
  }

}