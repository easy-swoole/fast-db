<?php
declare(strict_types=1);
/**
 * This file is part of EasySwoole.
 *
 * @link     https://www.easyswoole.com
 * @document https://www.easyswoole.com
 * @contact  https://www.easyswoole.com/Preface/contact.html
 * @license  https://github.com/easy-swoole/easyswoole/blob/3.x/LICENSE
 */

namespace EasySwoole\FastDb\Commands;

use EasySwoole\Command\AbstractInterface\CommandHelpInterface;
use EasySwoole\Command\CommandManager;
use EasySwoole\EasySwoole\Command\CommandInterface;

/**
 * This file is part of EasySwoole.
 *
 * @link     https://www.easyswoole.com
 * @document https://www.easyswoole.com
 * @contact  https://www.easyswoole.com/Preface/contact.html
 * @license  https://github.com/easy-swoole/easyswoole/blob/3.x/LICENSE
 */
class ModelCommand implements CommandInterface
{
    public function commandName(): string
    {
        return 'model';
    }

    public function exec(): ?string
    {
        $action = CommandManager::getInstance()->getArg(0);
        if ($action) {
            return $this->$action();
        }

        return CommandManager::getInstance()->displayCommandHelp($this->commandName());
    }

    public function help(CommandHelpInterface $commandHelp): CommandHelpInterface
    {
        $commandHelp->addAction('gen', 'Create a new model class.');
        $commandHelp->addActionOpt('-table', 'The name of the table to which the model wants to be linked. eg. -table=easyswoole_user.');
        $commandHelp->addActionOpt('-db-connection', 'Which connection pool you want the Model use. [default: "default"]. eg. -db-connection=default.');
        $commandHelp->addActionOpt('-path', 'The path that you want the Model file to be generated. eg: -path=App/Model.');
        $commandHelp->addActionOpt('-with-comments', 'Whether generate the property comments for model. eg: -with-comments=false.');
        return $commandHelp;
    }

    public function desc(): string
    {
        return 'Operate model classes';
    }

    private function gen()
    {
        return (new GenModelAction())->run();
    }
}
