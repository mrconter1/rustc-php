// stdout: 10
// stdout: 7
// stdout: 21
// stdout: 4
// exit: 4

enum Cmd {
    Add(i32),
    Sub(i32),
    Mul(i32),
    Set(i32),
}

fn main() {
    let mut acc: i32 = 0;
    let mut step: i32 = 0;
    loop {
        let cmd = if step == 0 { Cmd::Add(10) } else if step == 1 { Cmd::Sub(3) } else if step == 2 { Cmd::Mul(3) } else { Cmd::Set(4) };
        match cmd {
            Cmd::Add(x) => { acc = acc + x; }
            Cmd::Sub(x) => { acc = acc - x; }
            Cmd::Mul(x) => { acc = acc * x; }
            Cmd::Set(x) => { acc = x; }
        }
        println!("{}", acc);
        step = step + 1;
        if step >= 4 {
            break;
        }
    }
    exit(acc);
}
