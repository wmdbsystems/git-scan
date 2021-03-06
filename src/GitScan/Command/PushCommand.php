<?php
namespace GitScan\Command;

use GitScan\GitRepo;
use GitScan\Util\ArrayUtil;
use GitScan\Util\Filesystem;
use GitScan\Util\Process as ProcessUtil;
use GitScan\Util\Process;
use GitScan\Util\ProcessBatch;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;


class PushCommand extends BaseCommand {

  /**
   * @var Filesystem
   */
  var $fs;

  /**
   * @param string|null $name
   */
  public function __construct($name = NULL) {
    $this->fs = new Filesystem();
    parent::__construct($name);
  }

  protected function configure() {
    $this
      ->setName('push')
      ->setDescription('Push tags or branches on all repos')
      ->addOption('path', NULL, InputOption::VALUE_REQUIRED, 'The local base path to search', getcwd())
      ->addOption('prefix', 'p', InputOption::VALUE_NONE, 'Autodetect prefixed variations')
      ->addOption('dry-run', 'T', InputOption::VALUE_NONE, 'Display what would be done')
      ->addArgument('remote', InputArgument::REQUIRED, 'The name of the remote')
      ->addArgument('refspec', InputArgument::REQUIRED, 'The tag or branch to push');
  }

  protected function initialize(InputInterface $input, OutputInterface $output) {
    $input->setOption('path', $this->fs->toAbsolutePath($input->getOption('path')));
    $this->fs->validateExists($input->getOption('path'));
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $scanner = new \GitScan\GitRepoScanner();
    $gitRepos = $scanner->scan($input->getOption('path'));
    $remote = $input->getArgument('remote');
    $batch = new ProcessBatch('Pushing...');

    $branchQuoted = preg_quote($input->getArgument('refspec'), '/');
    $branchRegex = $input->getOption('prefix') ? "/^((.+[-_])|)$branchQuoted\$/" : "/^$branchQuoted\$/";

    foreach ($gitRepos as $gitRepo) {
      /** @var \GitScan\GitRepo $gitRepo */
      $relPath = $this->fs->makePathRelative($gitRepo->getPath(), $input->getOption('path'));

      $remotes = $gitRepo->getRemotes();
      if (!in_array($remote, $remotes)) {
        $output->writeln("<error>Repo \"<info>$relPath</info>\" does not have remote \"<info>$remote</info>\"</error>");
        return 1;
      }

      $names = array_merge(
        $gitRepo->getBranches(),
        $gitRepo->getTags()
      );
      $matchedNames = preg_grep($branchRegex, $names);

      // TODO: Interactively confirm/filter.

      foreach ($matchedNames as $name) {
        $batch->add(
          "In \"<info>$relPath</info>\", push \"<info>$name</info>\" to \"<info>$remote</info>\"",
          $gitRepo->command(sprintf(
            "git push %s %s",
            escapeshellarg($remote),
            escapeshellarg($name)))
        );
      }
    }

    $batch->runAllOk($output, $input->getOption('dry-run'));
  }

}
