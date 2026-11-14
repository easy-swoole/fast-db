<?php

namespace EasySwoole\FastDb\Internal;

/** 字段选择规则：写入应用白名单，输出另外应用隐藏字段。 */
final class FieldSelection
{
    public ?array $fields = null;
    public ?array $inheritedOutputFields = null;
    public ?array $inheritedHideFields = null;
    public ?array $hideFields = null;
    public bool $persistFieldLimit = false;

    /** 在字段转换前确定参与本次操作的属性，避免执行被排除字段的转换器。 */
    public function filterProperties(array $properties, bool $forOutput): array
    {
        $fields = $this->fields['fields'] ?? null;
        if ($forOutput && !array_key_exists('fields', $this->fields ?? [])) {
            $fields = $this->inheritedOutputFields;
        }
        if ($fields !== null) { $properties = array_intersect_key($properties, array_flip($fields)); }
        if ($forOutput) {
            $hideFields = $this->hideFields ?? $this->inheritedHideFields;
            if ($hideFields) { $properties = array_diff_key($properties, array_flip($hideFields)); }
        }
        return $properties;
    }
}
