// exit: 10

enum Step {
    Up,
    Down,
    Stay,
}

fn apply(val: i32, s: Step) -> i32 {
    match s {
        Step::Up   => { val + 1 }
        Step::Down => { val - 1 }
        Step::Stay => { val }
    }
}

fn main() {
    let mut v = 5;
    v = apply(v, Step::Up);
    v = apply(v, Step::Up);
    v = apply(v, Step::Up);
    v = apply(v, Step::Down);
    v = apply(v, Step::Up);
    v = apply(v, Step::Stay);
    v = apply(v, Step::Up);
    v = apply(v, Step::Up);
    exit(v);
}
