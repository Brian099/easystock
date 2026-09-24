<?php
/**
 * Search Helper Utility
 * 搜索辅助函数：提供数字大小写与汉字相互转换、变体扩展等功能
 */

/**
 * 将中文数字（小写、大写、组合词如"十二"、"二十五"、"一百零八"、"五"等）转换为阿拉伯数字字符串
 *
 * @param string $str
 * @return string|false
 */
function chinese_number_to_arabic($str) {
    $digit_map = [
        '零' => 0, '〇' => 0, '一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4,
        '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9,
        '壹' => 1, '贰' => 2, '叁' => 3, '参' => 3, '肆' => 4, '伍' => 5,
        '陆' => 6, '柒' => 7, '捌' => 8, '玖' => 9
    ];
    $unit_map = [
        '十' => 10, '拾' => 10,
        '百' => 100, '佰' => 100,
        '千' => 1000, '仟' => 1000,
        '万' => 10000,
        '亿' => 100000000
    ];

    $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($chars)) return false;

    // 检查是否全由中文数字或单位组成
    foreach ($chars as $c) {
        if (!isset($digit_map[$c]) && !isset($unit_map[$c])) {
            return false;
        }
    }

    // 单字情况
    if (count($chars) === 1) {
        $c = $chars[0];
        if (isset($digit_map[$c])) return (string)$digit_map[$c];
        if (isset($unit_map[$c])) return (string)$unit_map[$c];
    }

    // 特殊前缀："十" / "拾" 开头，例如 "十二" -> 12, "十五" -> 15
    if (($chars[0] === '十' || $chars[0] === '拾') && count($chars) === 2 && isset($digit_map[$chars[1]])) {
        return (string)(10 + $digit_map[$chars[1]]);
    }

    // 标准中文数值解析
    $total = 0;
    $section = 0;
    $number = 0;
    $has_unit = false;

    for ($i = 0; $i < count($chars); $i++) {
        $c = $chars[$i];
        if (isset($digit_map[$c])) {
            $number = $digit_map[$c];
            if ($i === count($chars) - 1) {
                $section += $number;
            }
        } elseif (isset($unit_map[$c])) {
            $has_unit = true;
            $unit = $unit_map[$c];
            if ($unit === 10000 || $unit === 100000000) {
                $section = ($section + $number) * $unit;
                $total += $section;
                $section = 0;
            } else {
                if ($number === 0 && $section === 0 && ($unit === 10)) {
                    $section = 10;
                } else {
                    $section += ($number !== 0 ? $number : 1) * $unit;
                }
            }
            $number = 0;
        }
    }
    $total += $section;

    // 如果没有单位（如"五一"或"一二三"），直接按位拼接
    if (!$has_unit && count($chars) > 1) {
        $res = '';
        foreach ($chars as $c) {
            $res .= isset($digit_map[$c]) ? $digit_map[$c] : '';
        }
        return $res;
    }

    return (string)$total;
}

/**
 * 将阿拉伯数值转换为中文数字词（小写或大写）
 * 例如 12 -> "十二" / "拾贰", 20 -> "二十" / "贰拾", 108 -> "一百零八"
 *
 * @param int $num
 * @param bool $upper 是否使用大写（财务大写）
 * @return string
 */
function arabic_to_chinese_value($num, $upper = false) {
    if (!is_numeric($num)) return '';
    $num = (int)$num;
    if ($num < 0 || $num > 99999999) return (string)$num;

    $digits_lower = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
    $digits_upper = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
    $units_lower = ['', '十', '百', '千', '万'];
    $units_upper = ['', '拾', '佰', '仟', '万'];

    $digits = $upper ? $digits_upper : $digits_lower;
    $units = $upper ? $units_upper : $units_lower;

    if ($num === 0) return $digits[0];

    // 单数字
    if ($num < 10) return $digits[$num];

    // 10 到 19 特殊读法："十" 而非 "一十"
    if ($num >= 10 && $num < 20 && !$upper) {
        if ($num === 10) return '十';
        return '十' . $digits[$num % 10];
    }
    if ($num >= 10 && $num < 20 && $upper) {
        if ($num === 10) return '拾';
        return '拾' . $digits[$num % 10];
    }

    $str_num = (string)$num;
    $len = strlen($str_num);
    $result = '';
    $zero = false;

    for ($i = 0; $i < $len; $i++) {
        $d = (int)$str_num[$i];
        $pos = $len - $i - 1;

        if ($pos === 4) { // 万位
            if ($d !== 0) {
                $result .= $digits[$d] . $units[$pos];
            } else {
                $result .= $units[4];
            }
            $zero = false;
        } elseif ($d === 0) {
            $zero = true;
        } else {
            if ($zero) {
                $result .= $digits[0];
                $zero = false;
            }
            $result .= $digits[$d] . $units[$pos % 4];
        }
    }

    return $result;
}

/**
 * 展开搜索查询的数字大小写与汉字变体
 *
 * @param string $query 原始搜索关键字
 * @return array 包含原始词及转换扩展后的变体数组
 */
function expand_search_number_variants($query) {
    $query = trim($query);
    if ($query === '') return [];

    // 0. 全角数字转半角数字归一化
    $fullwidth = ['０','１','２','３','４','５','６','７','８','９'];
    $halfwidth = ['0','1','2','3','4','5','6','7','8','9'];
    $normalized = str_replace($fullwidth, $halfwidth, $query);

    $variants = [$query];
    if ($normalized !== $query) {
        $variants[] = $normalized;
    }

    $c2d_map = [
        '零' => '0', '〇' => '0', '一' => '1', '二' => '2', '两' => '2',
        '三' => '3', '四' => '4', '五' => '5', '六' => '6', '七' => '7',
        '八' => '8', '九' => '9',
        '壹' => '1', '贰' => '2', '叁' => '3', '参' => '3', '肆' => '4',
        '伍' => '5', '陆' => '6', '柒' => '7', '捌' => '8', '玖' => '9'
    ];

    $d2lower_map = [
        '0' => '零', '1' => '一', '2' => '二', '3' => '三', '4' => '四',
        '5' => '五', '6' => '六', '7' => '七', '8' => '八', '9' => '九'
    ];

    $d2upper_map = [
        '0' => '零', '1' => '壹', '2' => '贰', '3' => '叁', '4' => '肆',
        '5' => '伍', '6' => '陆', '7' => '柒', '8' => '捌', '9' => '玖'
    ];

    $lower2upper_map = [
        '零' => '零', '〇' => '零', '一' => '壹', '二' => '贰', '两' => '贰',
        '三' => '叁', '四' => '肆', '五' => '伍', '六' => '陆', '七' => '柒',
        '八' => '捌', '九' => '玖', '十' => '拾', '百' => '佰', '千' => '仟'
    ];

    $upper2lower_map = [
        '壹' => '一', '贰' => '二', '叁' => '三', '参' => '三', '肆' => '四',
        '伍' => '五', '陆' => '六', '柒' => '七', '捌' => '八', '玖' => '九',
        '拾' => '十', '佰' => '百', '仟' => '千'
    ];

    // 1. 逐字符精确映射转换
    // 1a. 中文数字转阿拉伯数字（如 "五合一" -> "5合1", "伍合壹" -> "5合1"）
    $c2d_str = strtr($normalized, $c2d_map);
    if ($c2d_str !== $normalized) {
        $variants[] = $c2d_str;
    }

    // 1b. 阿拉伯数字转中文小写（如 "5合1" -> "五合一"）
    $d2l_str = strtr($normalized, $d2lower_map);
    if ($d2l_str !== $normalized) {
        $variants[] = $d2l_str;
    }

    // 1c. 阿拉伯数字转中文大写（如 "5合1" -> "伍合壹"）
    $d2u_str = strtr($normalized, $d2upper_map);
    if ($d2u_str !== $normalized) {
        $variants[] = $d2u_str;
    }

    // 1d. 中文小写转中文大写（如 "五合一" -> "伍合壹"）
    $l2u_str = strtr($normalized, $lower2upper_map);
    if ($l2u_str !== $normalized) {
        $variants[] = $l2u_str;
    }

    // 1e. 中文大写转中文小写（如 "伍合壹" -> "五合一"）
    $u2l_str = strtr($normalized, $upper2lower_map);
    if ($u2l_str !== $normalized) {
        $variants[] = $u2l_str;
    }

    // 1f. "两" <-> "二" <-> "2" 量词数字互换
    if (mb_strpos($normalized, '两') !== false) {
        $variants[] = str_replace('两', '二', $normalized);
        $variants[] = str_replace('两', '2', $normalized);
    }
    if (mb_strpos($normalized, '二') !== false) {
        $variants[] = str_replace('二', '两', $normalized);
    }
    if (strpos($normalized, '2') !== false) {
        $variants[] = str_replace('2', '两', $normalized);
    }

    // 2. 基于数值的读法转换
    // 2a. 阿拉伯数字转中文数值读法（如 "12代" -> "十二代", "拾贰代"; "20寸" -> "二十寸"）
    if (preg_match_all('/\d+/', $normalized, $matches, PREG_OFFSET_CAPTURE)) {
        $arabic_variants_lower = $normalized;
        $arabic_variants_upper = $normalized;
        $has_val_change = false;

        $ordered_matches = array_reverse($matches[0]);
        foreach ($ordered_matches as $m) {
            $num_str = $m[0];
            $num_val = (int)$num_str;
            if ($num_val >= 0 && $num_val <= 99999) {
                $c_val_lower = arabic_to_chinese_value($num_val, false);
                $c_val_upper = arabic_to_chinese_value($num_val, true);
                if ($c_val_lower !== '') {
                    $arabic_variants_lower = substr_replace($arabic_variants_lower, $c_val_lower, $m[1], strlen($num_str));
                    $arabic_variants_upper = substr_replace($arabic_variants_upper, $c_val_upper, $m[1], strlen($num_str));
                    $has_val_change = true;
                }
            }
        }
        if ($has_val_change) {
            $variants[] = $arabic_variants_lower;
            $variants[] = $arabic_variants_upper;
        }
    }

    // 2b. 中文数值读法转阿拉伯数字（如 "十二代" -> "12代", "二十寸" -> "20寸", "一百零八" -> "108"）
    if (preg_match_all('/[零〇一二两三四五六七八九十百千万壹贰叁参肆伍陆柒捌玖拾佰仟]+/u', $normalized, $c_matches, PREG_OFFSET_CAPTURE)) {
        $cn_variant = $normalized;
        $has_cn_val_change = false;
        $ordered_c_matches = array_reverse($c_matches[0]);
        foreach ($ordered_c_matches as $cm) {
            $c_str = $cm[0];
            $parsed_num = chinese_number_to_arabic($c_str);
            if ($parsed_num !== false && $parsed_num !== '') {
                $byte_offset = $cm[1];
                $byte_len = strlen($c_str);
                $cn_variant = substr_replace($cn_variant, $parsed_num, $byte_offset, $byte_len);
                $has_cn_val_change = true;
            }
        }
        if ($has_cn_val_change) {
            $variants[] = $cn_variant;
        }
    }

    // 去重并过滤空值
    $unique = [];
    foreach ($variants as $v) {
        $v = trim($v);
        if ($v !== '' && !in_array($v, $unique, true)) {
            $unique[] = $v;
        }
    }

    return $unique;
}

/**
 * 代码内置预设的常见符号互换等价组
 * 包含乘号/字母x、连字符/下划线、斜杠反斜杠、中英文括号、中英文句点、冒号等
 */
const BUILTIN_SEARCH_SYMBOL_GROUPS = [
    ['x', '*', 'X', '×', '✕', '✖'],
    ['-', '_', '—', '–'],
    ['/', '\\'],
    ['(', '（'],
    [')', '）'],
    ['[', '【', '［'],
    [']', '】', '］'],
    ['.', '。', '·', '•'],
    [':', '：'],
    [',', '，']
];

/**
 * 展开搜索查询中的符号互换变体（使用内置等价符号组）
 *
 * @param string $query 原始查询词
 * @return array 包含原始词及符号替换后的变体数组
 */
function expand_search_symbol_variants($query) {
    $query = trim($query);
    if ($query === '') return [];

    $groups = BUILTIN_SEARCH_SYMBOL_GROUPS;

    // 检查查询词中命中了哪些符号组
    $active_groups = [];
    foreach ($groups as $g) {
        foreach ($g as $sym) {
            if (mb_strpos($query, $sym) !== false) {
                $active_groups[] = $g;
                break;
            }
        }
    }
    if (empty($active_groups)) return [$query];

    // 针对命中的符号组进行全量替换扩展
    $current_variants = [$query];
    foreach ($active_groups as $g) {
        $next_variants = $current_variants;
        foreach ($current_variants as $var) {
            foreach ($g as $from_sym) {
                if (mb_strpos($var, $from_sym) === false) continue;
                foreach ($g as $to_sym) {
                    if ($from_sym === $to_sym) continue;
                    $replaced = str_replace($from_sym, $to_sym, $var);
                    if (!in_array($replaced, $next_variants, true)) {
                        $next_variants[] = $replaced;
                        if (count($next_variants) >= 50) break 4;
                    }
                }
            }
        }
        $current_variants = $next_variants;
    }

    return array_values(array_unique($current_variants));
}

/**
 * 辅助函数：根据系统设置和请求参数判断是否开启了数字转换
 *
 * @param PDO|null $pdo
 * @return bool
 */
function is_search_number_convert_enabled($pdo = null) {
    // 1. 若 URL 中明确传参，则优先依据传参
    if (isset($_GET['convert_num'])) {
        $val = strtolower(trim($_GET['convert_num']));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }

    // 2. 否则从数据库 setting 读取
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT searchNumberConvert FROM setting WHERE id = 1 LIMIT 1");
            if ($stmt && ($row = $stmt->fetch())) {
                return ($row['searchNumberConvert'] ?? 'true') === 'true';
            }
        } catch (Throwable $e) {
            // 字段尚未迁移时默认启用
            return true;
        }
    }

    return true;
}

/**
 * 辅助函数：根据系统设置和请求参数判断是否开启了符号互换
 *
 * @param PDO|null $pdo
 * @return bool
 */
function is_search_symbol_convert_enabled($pdo = null) {
    // 1. 若 URL 中明确传参，则优先依据传参
    if (isset($_GET['convert_symbol'])) {
        $val = strtolower(trim($_GET['convert_symbol']));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }

    // 2. 否则从数据库 setting 读取
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT searchSymbolConvert FROM setting WHERE id = 1 LIMIT 1");
            if ($stmt && ($row = $stmt->fetch())) {
                return ($row['searchSymbolConvert'] ?? 'true') === 'true';
            }
        } catch (Throwable $e) {
            // 字段尚未迁移时默认启用
            return true;
        }
    }

    return true;
}

/**
 * 辅助函数：根据系统设置和请求参数判断是否开启了搜索忽略/清理空格
 *
 * @param PDO|null $pdo
 * @return bool
 */
function is_search_space_ignore_enabled($pdo = null) {
    // 1. 若 URL 中明确传参，则优先依据传参
    if (isset($_GET['ignore_space'])) {
        $val = strtolower(trim($_GET['ignore_space']));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }
    if (isset($_GET['convert_space'])) {
        $val = strtolower(trim($_GET['convert_space']));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }

    // 2. 否则从数据库 setting 读取
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT searchSpaceIgnore FROM setting WHERE id = 1 LIMIT 1");
            if ($stmt && ($row = $stmt->fetch())) {
                return ($row['searchSpaceIgnore'] ?? 'true') === 'true';
            }
        } catch (Throwable $e) {
            // 字段尚未迁移时默认启用
            return true;
        }
    }

    return true;
}

/**
 * 构建单个字段的 LIKE 条件表达式和绑定参数
 * 支持根据是否开启空格忽略进行中英文空格剥离匹配
 *
 * @param string $col 列名
 * @param string $v 变体字符串
 * @param bool $space_ignore 是否启用空格忽略
 * @return array [$sql_fragment, $param_value]
 */
function build_search_like_condition($col, $v, $space_ignore = true) {
    if ($space_ignore) {
        $clean_v = str_replace([' ', '　', "\t", "\r", "\n"], '', $v);
        if ($clean_v !== '') {
            return ["REPLACE(REPLACE($col, ' ', ''), '　', '') LIKE ?", "%$clean_v%"];
        }
    }
    return ["$col LIKE ?", "%$v%"];
}

/**
 * 统一综合搜索关键字变体扩展函数（融合数字转换、符号互换与空格清理）
 *
 * @param string $query 原始查询词
 * @param PDO|null $pdo 数据库连接实例
 * @param array $options 自定义开关选项（可显式指定 enable_num, enable_symbol, enable_space）
 * @return array 去重后的变体列表
 */
function expand_search_variants($query, $pdo = null, $options = []) {
    $query = trim($query);
    if ($query === '') return [];

    $enable_num = isset($options['enable_num']) ? (bool)$options['enable_num'] : is_search_number_convert_enabled($pdo);
    $enable_symbol = isset($options['enable_symbol']) ? (bool)$options['enable_symbol'] : is_search_symbol_convert_enabled($pdo);
    $enable_space = isset($options['enable_space']) ? (bool)$options['enable_space'] : is_search_space_ignore_enabled($pdo);

    if (!$enable_num && !$enable_symbol) {
        $base_variants = [$query];
    } else {
        // 1. 基础数字扩展
        $num_variants = $enable_num ? expand_search_number_variants($query) : [$query];
        if (empty($num_variants)) {
            $num_variants = [$query];
        }

        // 2. 若未开启符号替换，直接返回数字变体
        if (!$enable_symbol) {
            $base_variants = array_values(array_unique($num_variants));
        } else {
            // 3. 在数字变体的基础上进行代码内置符号扩展
            $base_variants = [];

            foreach ($num_variants as $nv) {
                $sym_vars = expand_search_symbol_variants($nv);
                foreach ($sym_vars as $sv) {
                    $sv = trim($sv);
                    if ($sv !== '' && !in_array($sv, $base_variants, true)) {
                        $base_variants[] = $sv;
                        if (count($base_variants) >= 50) break 2;
                    }
                }
            }
        }
    }

    $all_variants = $base_variants;

    // 4. 若开启空格清理，且原词包含空格，增加去空格变体
    if ($enable_space) {
        foreach ($base_variants as $bv) {
            if (strpos($bv, ' ') !== false || mb_strpos($bv, '　') !== false) {
                $no_sp = str_replace([' ', '　', "\t"], '', $bv);
                if ($no_sp !== '' && !in_array($no_sp, $all_variants, true)) {
                    $all_variants[] = $no_sp;
                    if (count($all_variants) >= 50) break;
                }
            }
        }
    }

    return !empty($all_variants) ? $all_variants : [$query];
}
