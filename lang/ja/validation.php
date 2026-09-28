<?php

// 入力エラーの日本語メッセージ（APP_LOCALE=ja）。項目名は各 FormRequest の attributes() で与える
return [
    'accepted' => ':attributeを承認してください',
    'array' => ':attributeの形式が正しくありません',
    'between' => [
        'numeric' => ':attributeは:min〜:maxにしてください',
        'string' => ':attributeは:min〜:max文字にしてください',
        'array' => ':attributeは:min〜:max件にしてください',
        'file' => ':attributeは:min〜:max KBにしてください',
    ],
    'boolean' => ':attributeの形式が正しくありません',
    'confirmed' => ':attributeが確認用と一致しません',
    'current_password' => 'パスワードが違います',
    'date' => ':attributeは正しい日付にしてください',
    'date_format' => ':attributeは:formatの形式にしてください',
    'different' => ':attributeと:otherは違うものにしてください',
    'digits' => ':attributeは:digits桁の数字にしてください',
    'distinct' => ':attributeが重複しています',
    'exists' => '選択された:attributeは見つかりません',
    'file' => ':attributeはファイルにしてください',
    'filled' => ':attributeを入力してください',
    'gt' => [
        'numeric' => ':attributeは:valueより大きくしてください',
    ],
    'gte' => [
        'numeric' => ':attributeは:value以上にしてください',
    ],
    'in' => '選択された:attributeは正しくありません',
    'integer' => ':attributeは整数にしてください',
    'list' => ':attributeの形式が正しくありません',
    'lt' => [
        'numeric' => ':attributeは:valueより小さくしてください',
    ],
    'lte' => [
        'numeric' => ':attributeは:value以下にしてください',
    ],
    'max' => [
        'numeric' => ':attributeは:max以下にしてください',
        'string' => ':attributeは:max文字以内にしてください',
        'array' => ':attributeは:max件以内にしてください',
        'file' => ':attributeは:max KB以内にしてください',
    ],
    'mimes' => ':attributeは:values形式のファイルにしてください',
    'mimetypes' => ':attributeは:values形式のファイルにしてください',
    'min' => [
        'numeric' => ':attributeは:min以上にしてください',
        'string' => ':attributeは:min文字以上にしてください',
        'array' => ':attributeは:min件以上にしてください',
        'file' => ':attributeは:min KB以上にしてください',
    ],
    'not_in' => '選択された:attributeは正しくありません',
    'numeric' => ':attributeは数値にしてください',
    'present' => ':attributeがありません',
    'prohibited' => ':attributeは指定できません',
    'regex' => ':attributeの形式が正しくありません',
    'required' => ':attributeを入力してください',
    'required_if' => ':otherが:valueのときは:attributeを入力してください',
    'required_with' => ':valuesがあるときは:attributeを入力してください',
    'same' => ':attributeと:otherが一致しません',
    'size' => [
        'numeric' => ':attributeは:sizeにしてください',
        'string' => ':attributeは:size文字にしてください',
        'array' => ':attributeは:size件にしてください',
        'file' => ':attributeは:size KBにしてください',
    ],
    'string' => ':attributeは文字列にしてください',
    'unique' => 'その:attributeは既に使われています',
    'uuid' => ':attributeの形式が正しくありません',

    'attributes' => [],
];
