// exit: 0

// ── const, static ──
const MAX_HP: i32 = 100;
const ARENA_SIZE: i32 = 8;
static BASE_XP: i32 = 10;

// ── const fn ──
const fn crit_multiplier() -> i32 {
    2
}

// ── enum with tuple payloads, unit variant ──
enum Element {
    Fire(i32),
    Ice(i32),
    Void,
}

enum BattleResult {
    Victory(i32),
    Defeat,
    Stalemate,
}

// ── structs, nested structs, pub fields ──
struct Position {
    pub x: i32,
    pub y: i32,
}

struct Stats {
    pub strength: i32,
    pub defense: i32,
    pub speed: i32,
}

struct Fighter {
    pub hp: i32,
    pub max_hp: i32,
    pub stats: Stats,
    pub pos: Position,
    pub alive: bool,
    pub level: i32,
}

// ── generic struct ──
struct Pair<T> {
    pub first: T,
    pub second: T,
}

// ── trait with default method ──
trait Combatant {
    fn attack_power(&self) -> i32;
    fn is_threat(&self) -> bool {
        true
    }
}

// ── impl Trait for Type ──
impl Combatant for Fighter {
    fn attack_power(&self) -> i32 {
        self.stats.strength + BASE_XP / 2
    }
    fn is_threat(&self) -> bool {
        self.alive
    }
}

// ── impl block: &self, &mut self ──
impl Fighter {
    fn take_damage(&mut self, raw: i32) {
        let actual = raw - self.stats.defense;
        if actual > 0 {
            self.hp -= actual;
        }
        if self.hp <= 0 {
            self.hp = 0;
            self.alive = false;
        }
    }

    fn heal(&mut self, amount: i32) {
        self.hp += amount;
        if self.hp > self.max_hp {
            self.hp = self.max_hp;
        }
    }

    fn manhattan_dist(&self, other: &Fighter) -> i32 {
        let dx = self.pos.x - other.pos.x;
        let dy = self.pos.y - other.pos.y;
        let abs_dx = if dx < 0 { 0 - dx } else { dx };
        let abs_dy = if dy < 0 { 0 - dy } else { dy };
        abs_dx + abs_dy
    }

    fn level_up(&mut self) {
        self.level += 1;
        self.stats.strength += 2;
        self.stats.defense += 1;
        self.stats.speed += 1;
        self.max_hp += 10;
        self.hp = self.max_hp;
    }
}

// ── Option<T> ──
fn elemental_bonus(elem: &Element) -> Option<i32> {
    match elem {
        Element::Fire(n) => Some(*n * crit_multiplier()),
        Element::Ice(n) => Some(*n + 3),
        Element::Void => None,
    }
}

// ── Result<T, E> ──
fn validate_coord(v: i32) -> Result<i32, i32> {
    if v >= 0 {
        if v < ARENA_SIZE {
            Ok(v)
        } else {
            Err(ARENA_SIZE - 1)
        }
    } else {
        Err(0)
    }
}

// ── closures as function arguments (NEW FEATURE) ──
fn transform(f: impl Fn(i32) -> i32, x: i32) -> i32 {
    f(x)
}

fn compose(f: impl Fn(i32) -> i32, g: impl Fn(i32) -> i32, x: i32) -> i32 {
    f(g(x))
}

fn apply_if(pred: impl Fn(i32) -> bool, f: impl Fn(i32) -> i32, x: i32) -> i32 {
    if pred(x) {
        f(x)
    } else {
        x
    }
}

// ── zero-parameter closure as argument (NEW FEATURE) ──
fn run_action(action: impl Fn() -> i32) -> i32 {
    action()
}

// ── free function, multiple params, explicit return ──
fn clamp(val: i32, lo: i32, hi: i32) -> i32 {
    if val < lo {
        return lo;
    }
    if val > hi {
        return hi;
    }
    val
}

fn simulate_round(hero: &mut Fighter, enemy_power: i32, elem: &Element) -> BattleResult {
    // ── if let ──
    let bonus = if let Some(b) = elemental_bonus(elem) {
        b
    } else {
        0
    };

    let hero_dmg = hero.attack_power() + bonus;
    let mut enemy_hp: i32 = 40;
    let mut rounds: i32 = 0;

    // ── loop, break, compound assignment ──
    loop {
        enemy_hp -= hero_dmg;
        if enemy_hp <= 0 {
            break;
        }
        hero.take_damage(enemy_power);
        if !hero.alive {
            return BattleResult::Defeat;
        }
        rounds += 1;
        if rounds > 20 {
            return BattleResult::Stalemate;
        }
    }

    let xp = BASE_XP * (rounds + 1);
    BattleResult::Victory(xp)
}

fn main() {
    println!("{}", "=== Dungeon Crawler Simulation ===");

    // ── struct construction, nested structs, const usage ──
    let mut hero = Fighter {
        hp: MAX_HP,
        max_hp: MAX_HP,
        stats: Stats { strength: 12, defense: 4, speed: 6 },
        pos: Position { x: 0, y: 0 },
        alive: true,
        level: 1,
    };

    let sentinel = Fighter {
        hp: 50,
        max_hp: 50,
        stats: Stats { strength: 8, defense: 2, speed: 3 },
        pos: Position { x: 3, y: 4 },
        alive: true,
        level: 1,
    };

    // ── method call, &self, references ──
    let dist = hero.manhattan_dist(&sentinel);
    println!("{}", dist); // 7

    // ── trait method + default method ──
    let power = hero.attack_power();
    println!("{}", power); // 12 + 5 = 17
    let threat = hero.is_threat();
    println!("{}", threat); // true

    // ── &mut self method, compound assignment ──
    hero.take_damage(20);
    println!("{}", hero.hp); // 100 - (20-4) = 84

    hero.heal(16);
    println!("{}", hero.hp); // 100 (capped at max_hp)

    // ── tuple, destructuring ──
    let coords = (5, 3);
    let (cx, cy) = coords;
    println!("{}", cx + cy); // 8

    // ── generic struct instantiation ──
    let bounds = Pair { first: 0, second: ARENA_SIZE };
    println!("{}", bounds.second - bounds.first); // 8

    // ── bool, if/else as expression ──
    let in_range = cx < ARENA_SIZE;
    let range_flag = if in_range { 1 } else { 0 };
    println!("{}", range_flag); // 1

    // ── enum match with wildcard ──
    let elem = Element::Fire(6);
    let elem_label = match elem {
        Element::Fire(_) => 1,
        Element::Ice(_) => 2,
        Element::Void => 0,
    };
    println!("{}", elem_label); // 1

    // ── Option: if let + match with Some/None ──
    let fire_bonus = elemental_bonus(&Element::Fire(6));
    if let Some(fb) = fire_bonus {
        println!("{}", fb); // 12 (6 * 2)
    }

    let void_bonus = elemental_bonus(&Element::Void);
    let void_val = match void_bonus {
        Some(v) => v,
        None => -1,
    };
    println!("{}", void_val); // -1

    // ── Result: match with Ok/Err ──
    match validate_coord(5) {
        Ok(v) => println!("{}", v),   // 5
        Err(e) => println!("{}", e),
    }
    match validate_coord(99) {
        Ok(v) => println!("{}", v),
        Err(e) => println!("{}", e),  // 7
    }
    match validate_coord(-3) {
        Ok(v) => println!("{}", v),
        Err(e) => println!("{}", e),  // 0
    }

    // ── Box<T>, deref ──
    let boxed_val = Box::new(777);
    let unboxed = *boxed_val;
    println!("{}", unboxed); // 777

    // ── Vec<i32>: new, push, index, index assign ──
    let mut damage_log = Vec::new();
    damage_log.push(10);
    damage_log.push(25);
    damage_log.push(30);
    damage_log.push(15);
    damage_log.push(20);
    damage_log.push(35);

    println!("{}", damage_log[0]); // 10
    damage_log[2] = 33;
    println!("{}", damage_log[2]); // 33

    // ── for x in start..end (range) ──
    let mut total_dmg = 0;
    for i in 0..6 {
        total_dmg += damage_log[i];
    }
    println!("{}", total_dmg); // 10+25+33+15+20+35 = 138

    // ── continue ──
    let mut big_hit_sum = 0;
    for i in 0..6 {
        if damage_log[i] < 20 {
            continue;
        }
        big_hit_sum += damage_log[i];
    }
    println!("{}", big_hit_sum); // 25+33+20+35 = 113

    // ── while ──
    let mut countdown = 5;
    while countdown > 0 {
        countdown -= 1;
    }
    println!("{}", countdown); // 0

    // ── nested loops with break level ──
    let mut found_product = 0;
    let mut i = 0;
    while i < 6 {
        let mut j = 0;
        while j < 6 {
            if damage_log[i] + damage_log[j] == 58 {
                found_product = damage_log[i] * 100 + damage_log[j];
                break 2;
            }
            j += 1;
        }
        i += 1;
    }
    println!("{}", found_product); // 25+33=58 → 2533

    // ── while let ──
    let mut opt_counter = 3;
    let mut wl_sum = 0;
    while let Some(val) = if opt_counter > 0 { Some(opt_counter) } else { None } {
        wl_sum += val;
        opt_counter -= 1;
    }
    println!("{}", wl_sum); // 3+2+1 = 6

    // ── closures with capture-by-value (original feature) ──
    let offset = 100;
    let add_offset = |x: i32| x + offset;
    println!("{}", add_offset(42)); // 142

    // ── closures as function arguments (NEW) ──
    let scale = 3;
    let tripled = transform(|x: i32| x * scale, 7);
    println!("{}", tripled); // 21

    let chained = compose(|x: i32| x + 1, |x: i32| x * 2, 5);
    println!("{}", chained); // (5*2)+1 = 11

    let big = apply_if(|x: i32| x > 10, |x: i32| x * 2, 15);
    println!("{}", big); // 30

    let small = apply_if(|x: i32| x > 10, |x: i32| x * 2, 5);
    println!("{}", small); // 5 (unchanged)

    // ── zero-param closure as argument (NEW) ──
    let secret = 99;
    let revealed = run_action(|| secret);
    println!("{}", revealed); // 99

    // ── iterators: map, filter, chained, for-in (NEW) ──
    let doubled = damage_log.iter().map(|x: i32| x * 2).collect();
    println!("{}", doubled[0]); // 20
    println!("{}", doubled[5]); // 70

    let big_hits = damage_log.iter().filter(|x: i32| x >= 25).collect();
    println!("{}", big_hits[0]); // 25

    let processed = damage_log.iter()
        .filter(|x: i32| x > 15)
        .map(|x: i32| x + 1000)
        .collect();
    println!("{}", processed[0]); // 1025
    println!("{}", processed[1]); // 1033

    let mut iter_total = 0;
    for x in damage_log.iter() {
        iter_total += x;
    }
    println!("{}", iter_total); // 138

    // ── battle simulation (exercises loop, break, return, enum match, if let, methods) ──
    let outcome = simulate_round(&mut hero, 8, &Element::Ice(4));
    match outcome {
        BattleResult::Victory(xp) => println!("{}", xp),
        BattleResult::Defeat => println!("{}", -1),
        BattleResult::Stalemate => println!("{}", 0),
    }

    // ── level_up: compound assignment on struct fields through &mut self ──
    hero.level_up();
    println!("{}", hero.level); // 2
    println!("{}", hero.stats.strength); // 14 (12+2)
    println!("{}", hero.max_hp); // 110

    // ── &str indexing ──
    let banner: &str = "VICTORY";
    let first_byte = banner[0]; // 'V' = 86
    println!("{}", first_byte);

    // ── compound assignment: all four operators ──
    let mut acc = 200;
    acc += 50;
    acc -= 30;
    acc *= 2;
    acc /= 4;
    println!("{}", acc); // ((200+50-30)*2)/4 = (220*2)/4 = 440/4 = 110

    // ── u8, u32, usize ──
    let byte_val: u8 = 255;
    let wide_val: u32 = 70000;
    let idx: usize = 3;
    println!("{}", damage_log[idx]); // 15

    // ── unit type ──
    let _nothing: () = ();

    // ── return, explicit exit ──
    println!("{}", "=== Simulation Complete ===");
    exit(0);
}