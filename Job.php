<?php

namespace FreePBX\modules\Registrationwatch;

use FreePBX;
use FreePBX\Job\TaskInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class Job implements TaskInterface {
        public static function run(InputInterface $input, OutputInterface $output) {
                try {
                        return FreePBX::Registrationwatch()->runBackgroundMonitor($output);
                } catch (Throwable $e) {
                        $output->writeln('<error>Registration Watch background job failed: ' . $e->getMessage() . '</error>');
                        return false;
                }
        }
}
