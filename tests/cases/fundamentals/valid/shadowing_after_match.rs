// exit: 0
// stdout: 11
// stdout: 22

fn main() {
    let x: Option<i32> = Option::<i32>::Some(10);
    let x = match x {
        Option::<i32>::Some(n) => n + 1,
        Option::<i32>::None => 0,
    };
    println!("{}", x);
    let x = x + 11;
    println!("{}", x);
}
