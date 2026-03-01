// exit: 0
// stdout: 7
#[derive(Debug)]
#[allow(dead_code)]
fn foo() -> i32 {
    7
}

fn main() {
    println!("{}", foo());
}
