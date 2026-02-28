// exit: 42

enum Opt {
    Some(i32),
    None,
}

fn unwrap_or(o: Opt, default: i32) -> i32 {
    match o {
        Opt::Some(v) => { v }
        Opt::None    => { default }
    }
}

fn main() {
    let a = Opt::Some(42);
    let b = Opt::None;
    let x = unwrap_or(a, 0);
    let y = unwrap_or(b, 99);
    exit(x);
}
