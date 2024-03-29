<?php

namespace Faker\ORM\CakePHP;

use Cake\ORM\TableRegistry;
use Faker\Guesser\Name as NameGuesser;

class EntityPopulator
{
    protected $class;
    protected $columnFormatters = [];
    protected $modifiers = [];

    public function __construct($class)
    {
        $this->class = $class;
    }

    public function __get($name)
    {
        return $this->{$name};
    }

    public function __set($name, $value)
    {
        $this->{$name} = $value;
    }

    public function mergeColumnFormattersWith($columnFormatters)
    {
        $this->columnFormatters = array_merge($this->columnFormatters, $columnFormatters);
    }

    public function mergeModifiersWith($modifiers)
    {
        $this->modifiers = array_merge($this->modifiers, $modifiers);
    }

    public function guessColumnFormatters($populator)
    {
        $formatters = [];
        $class = $this->class;
        $table = TableRegistry::get($class);
        $schema = $table->schema();
        $pk = $schema->primaryKey();
        $guessers = $populator->getGuessers() + ['ColumnTypeGuesser' => new ColumnTypeGuesser($populator->getGenerator())];
        $isForeignKey = function ($column) use ($table) {
            foreach ($table->associations()->type('BelongsTo') as $assoc) {
                if ($column == $assoc->foreignKey()) {
                    return true;
                }
            }
            return false;
        };


        foreach ($schema->columns() as $column) {
            if ($column == $pk[0] || $isForeignKey($column)) {
                continue;
            }

            foreach ($guessers as $guesser) {
                if ($formatter = $guesser->guessFormat($column, $table)) {
                    $formatters[$column] = $formatter;
                    break;
                }
            }
        }

        return $formatters;
    }

    public function guessModifiers($populator)
    {
        $modifiers = [];
        $table = TableRegistry::get($this->class);

        $belongsTo = $table->associations()->type('BelongsTo');
        foreach ($belongsTo as $assoc) {
            $modifiers['belongsTo' . $assoc->name()] = function ($data, $insertedEntities) use ($assoc) {
                $table = $assoc->target();
                $foreignModel = $table->alias();
                $foreignKey = $insertedEntities[$foreignModel][array_rand($insertedEntities[$foreignModel])];
                $primaryKey = $table->primaryKey();
                $data[$assoc->foreignKey()] = $foreignKey;
                return $data;
            };
        }

        // TODO check if TreeBehavior attached to modify lft/rgt cols

        return $modifiers;
    }

    public function execute($class, $insertedEntities, $options = [])
    {
        $table = TableRegistry::get($class);
        $entity = $table->newEntity();

        foreach ($this->columnFormatters as $column => $format) {
            if (!is_null($format)) {
                $entity->{$column} = is_callable($format) ? $format($insertedEntities, $table) : $format;
            }
        }

        foreach ($this->modifiers as $modifier) {
            $entity = $modifier($entity, $insertedEntities);
        }

        if (!$entity = $table->save($entity, $options)) {
            throw new \RuntimeException("Failed saving $class record");
        }

        $pk = $table->primaryKey();
        return $entity->{$pk};
    }
}
