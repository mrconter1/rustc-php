// exit: 0
// stdout: 1
// stdout: 0
// stdout: -1

enum Sign {
    Positive,
    Zero,
    Negative,
}

fn signum(n: i32) -> Sign {
    if n > 0 {
        Sign::Positive
    } else if n == 0 {
        Sign::Zero
    } else {
        Sign::Negative
    }
}

fn print_sign(s: Sign) {
    match s {
        Sign::Positive => { println!("1"); }
        Sign::Zero     => { println!("0"); }
        Sign::Negative => { println!("-1"); }
    }
}

fn main() {
    print_sign(signum(5));
    print_sign(signum(0));
    print_sign(signum(-3));
}
