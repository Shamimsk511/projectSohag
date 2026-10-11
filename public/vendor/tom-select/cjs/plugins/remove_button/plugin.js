"use strict";
/**
 * Plugin: "remove_button" (Tom Select)
 * Copyright (c) contributors
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use this
 * file except in compliance with the License. You may obtain a copy of the License at:
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software distributed under
 * the License is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF
 * ANY KIND, either express or implied. See the License for the specific language
 * governing permissions and limitations under the License.
 *
 */
Object.defineProperty(exports, "__esModule", { value: true });
exports.default = default_1;
const vanilla_ts_1 = require("../../vanilla.js");
const utils_ts_1 = require("../../utils.js");
function default_1(userOptions) {
    const self = this;
    const options = Object.assign({
        label: '×',
        title: 'Remove',
        className: 'remove',
        tabindex: -1,
        role: 'button',
        html: (data) => {
            var _a;
            const el = document.createElement('div');
            el.className = data.className || '';
            el.title = data.title || '';
            el.setAttribute('role', data.role || 'button');
            el.tabIndex = (_a = data.tabindex) !== null && _a !== void 0 ? _a : -1;
            el.textContent = data.label || '';
            return el;
        }
    }, userOptions);
    self.hook('after', 'setupTemplates', () => {
        var orig_render_item = self.settings.render.item;
        self.settings.render.item = (data, escape) => {
            var item = (0, vanilla_ts_1.getDom)(orig_render_item.call(self, data, escape));
            var close_button = (0, vanilla_ts_1.getDom)(options.html(options));
            item.appendChild(close_button);
            (0, utils_ts_1.addEvent)(close_button, 'mousedown', (evt) => {
                (0, utils_ts_1.preventDefault)(evt, true);
            });
            (0, utils_ts_1.addEvent)(close_button, 'click', (evt) => {
                if (self.isLocked)
                    return;
                // propagating will trigger the dropdown to show for single mode
                (0, utils_ts_1.preventDefault)(evt, true);
                if (self.isLocked)
                    return;
                if (!self.shouldDelete([item], evt))
                    return;
                self.removeItem(item);
                self.refreshOptions(false);
                self.inputState();
            });
            return item;
        };
    });
}
;
//# sourceMappingURL=plugin.js.map