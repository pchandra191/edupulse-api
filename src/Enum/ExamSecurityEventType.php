<?php
declare(strict_types=1);

namespace App\Enum;

enum ExamSecurityEventType: string
{
    case TabSwitch = 'TAB_SWITCH';
    case FullscreenExit = 'FULLSCREEN_EXIT';
    case PageRefresh = 'PAGE_REFRESH';
    case WindowBlur = 'WINDOW_BLUR';
    case DevtoolsOpen = 'DEVTOOLS_OPEN';
    case CopyAttempt = 'COPY_ATTEMPT';
    case PasteAttempt = 'PASTE_ATTEMPT';
    case RightClick = 'RIGHT_CLICK';
    case PrintAttempt = 'PRINT_ATTEMPT';
}
