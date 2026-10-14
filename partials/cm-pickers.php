<?php
/**
 * Community composer: the "People" (tag people) and "Feeling/activity" controls, used by both the new-post box and the
 * edit form so they stay identical. Include it twice per form:
 *   $cmPart = 'buttons'  the two chips, for the compose bar
 *   $cmPart = 'panels'   what's chosen (removable chips) and the two pop-over panels, placed under the bar
 * $cmT is the Vue object being edited ('compose' or 'editing') and $cmK its key for the open panel ('compose' or 'edit').
 */
if ($cmPart === 'buttons'): ?>
          <button type="button" class="cm-chip" :class="{ on: <?= $cmT ?>.tags.length }" @click.stop="togglePanel('<?= $cmK ?>', 'people')" :aria-expanded="panel.key === '<?= $cmK ?>' && panel.which === 'people' ? 'true' : 'false'" aria-haspopup="dialog"><span class="cm-ic" v-html="ic('usertag', 16)"></span>People<span v-if="<?= $cmT ?>.tags.length"> · {{ <?= $cmT ?>.tags.length }}</span></button>
          <button type="button" class="cm-chip" :class="{ on: <?= $cmT ?>.feeling }" @click.stop="togglePanel('<?= $cmK ?>', 'feeling')" :aria-expanded="panel.key === '<?= $cmK ?>' && panel.which === 'feeling' ? 'true' : 'false'" aria-haspopup="dialog"><span class="cm-ic" v-html="ic('smile', 16)"></span>Feeling/activity</button>
<?php else: ?>
        <div v-if="<?= $cmT ?>.feeling || <?= $cmT ?>.tags.length" class="cm-chosen">
          <span v-if="<?= $cmT ?>.feeling" class="cm-pick-chip">{{ feelingOf(<?= $cmT ?>.feeling).emoji }} {{ feelingOf(<?= $cmT ?>.feeling).text }}<button type="button" @click="<?= $cmT ?>.feeling = null" aria-label="Remove feeling or activity">×</button></span>
          <span v-for="u in <?= $cmT ?>.tags" :key="u.id" class="cm-pick-chip"><avatar :user="u" :size="18"></avatar>{{ u.name }}<button type="button" @click="removeTag('<?= $cmK ?>', u.id)" :aria-label="'Remove ' + u.name">×</button></span>
        </div>

        <div v-if="panel.key === '<?= $cmK ?>' && panel.which === 'people'" class="cm-panel" role="dialog" aria-label="Tag people" @click.stop @keydown.esc="panel.which = ''">
          <div class="cm-panel-head"><b>Tag people</b><button type="button" class="cm-x" @click="panel.which = ''" aria-label="Close">×</button></div>
          <input class="cm-input" v-model="people.q" @input="searchPeople" ref="peopleInput" maxlength="60" autocomplete="off" placeholder="Search by name or @username" aria-label="Search people" />
          <ul v-if="people.items.length" class="cm-people">
            <li v-for="u in people.items" :key="u.id"><button type="button" class="cm-person" @click="addTag('<?= $cmK ?>', u)"><avatar :user="u" :size="30"></avatar><span class="cm-person-name">{{ u.name }}<small v-if="u.username"> @{{ u.username }}</small></span></button></li>
          </ul>
          <p v-else-if="people.q.trim().length >= 2 && !people.loading" class="cm-panel-empty">No one found.</p>
          <p v-else-if="people.q.trim().length < 2" class="cm-panel-empty">Type at least 2 letters. You can tag up to {{ MAX_TAGS }} people.</p>
        </div>

        <div v-if="panel.key === '<?= $cmK ?>' && panel.which === 'feeling'" class="cm-panel" role="dialog" aria-label="Feeling or activity" @click.stop @keydown.esc="panel.which = ''">
          <div class="cm-panel-head"><b>How are you doing?</b><button type="button" class="cm-x" @click="panel.which = ''" aria-label="Close">×</button></div>
          <div class="cm-tabs-mini" role="tablist">
            <button type="button" role="tab" :aria-selected="feelTab === 'feeling' ? 'true' : 'false'" :class="{ on: feelTab === 'feeling' }" @click="feelTab = 'feeling'">Feelings</button>
            <button type="button" role="tab" :aria-selected="feelTab === 'activity' ? 'true' : 'false'" :class="{ on: feelTab === 'activity' }" @click="feelTab = 'activity'">Activities</button>
          </div>
          <div class="cm-feel-grid">
            <button v-for="f in feelList()" :key="f.key" type="button" class="cm-feel-btn" :class="{ on: <?= $cmT ?>.feeling === f.key }" @click="pickFeeling('<?= $cmK ?>', f.key)"><span class="cm-feel-emoji">{{ f.emoji }}</span><span>{{ f.label }}</span></button>
          </div>
          <button v-if="<?= $cmT ?>.feeling" type="button" class="cm-more" @click="<?= $cmT ?>.feeling = null; panel.which = ''">Remove</button>
        </div>
<?php endif; ?>
