<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Commands;

use EasySwoole\Command\AbstractInterface\AbstractCommand;
use EasySwoole\Command\Bean\Action;
use EasySwoole\Command\Bean\Caller;
use EasySwoole\Command\Bean\Option;
use EasySwoole\Command\Bean\Result;

class ModelCommand extends AbstractCommand
{
    public function name(): string
    {
        return 'model';
    }

    public function description(): string
    {
        return 'Operate model classes';
    }

    protected function init(): void
    {
        $action = new Action('gen', 'Create a new model class.');
        $action->addOption(new Option('table', 'Table name, e.g. --table=easyswoole_user'));
        $action->addOption(new Option('db-connection', 'Database connection name [default: default]'));
        $action->addOption(new Option('path', 'Model directory [default: App/Model]'));
        $action->addOption(new Option('with-comments', 'Include column comments; --with-comments=false disables them'));
        $action->setCallback(function (Caller $caller, Result $result): void {
            (new GenModelAction())->run($caller, $result);
        });
        $this->registerAction($action);
    }
}
