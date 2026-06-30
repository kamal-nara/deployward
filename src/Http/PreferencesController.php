<?php

namespace Deployward\Http;

final class PreferencesController
{
    const META_KEY = 'deployward_theme';
    const THEMES = array('light', 'dark');

    public function get(): ApiResponse
    {
        $theme = self::sanitizeTheme(get_user_meta(get_current_user_id(), self::META_KEY, true));
        return ApiResponse::ok(array('theme' => $theme));
    }

    public function save(array $params): ApiResponse
    {
        $theme = isset($params['theme']) ? (string) $params['theme'] : '';
        if (! in_array($theme, self::THEMES, true)) {
            return ApiResponse::error('theme must be light or dark', 422);
        }
        update_user_meta(get_current_user_id(), self::META_KEY, $theme);
        return ApiResponse::ok(array('theme' => $theme));
    }

    public static function sanitizeTheme($value): string
    {
        return ($value === 'dark') ? 'dark' : 'light';
    }
}
